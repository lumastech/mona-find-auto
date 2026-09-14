<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Enums\PaymentSource;
use App\Modules\Payments\Models\LencoWebhookEvent;
use App\Modules\Payments\Services\CollectionService;
use App\Modules\Payments\Services\PayoutService;
use App\Modules\Payments\Services\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Act on one stored webhook.
 *
 * ## What the event is and is not
 *
 * The payload is treated as a NOTIFICATION, never as evidence. It says "go
 * and look at reference X"; everything the platform then believes comes from
 * asking Lenco directly. A signed body still only proves it came from Lenco,
 * not that its contents are the current truth — a `collection.successful`
 * retried an hour after a reversal would otherwise settle a reversed order.
 *
 * ## Idempotency, twice over
 *
 * The event row is unique on the gateway's event id, so a redelivery never
 * reaches this job. And if one somehow did, the observation the job writes is
 * unique on (reference, status), so it would still act once. Two independent
 * guarantees, because this is the money path.
 */
class ProcessLencoWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * Retried on failure: a webhook that could not be processed because the
     * database was briefly unavailable is worth another go, and the work is
     * idempotent so a retry is free.
     */
    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public readonly int $eventId) {}

    public function handle(
        CollectionService $collections,
        PayoutService $payouts,
        RefundService $refunds,
    ): void {
        $event = LencoWebhookEvent::find($this->eventId);

        if ($event === null || $event->isProcessed()) {
            return;
        }

        $event->increment('attempts');

        try {
            $this->dispatchEvent($event, $collections, $payouts, $refunds);

            $event->forceFill(['processed_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $exception) {
            /*
             * The error is recorded on the row before it is re-thrown, so the
             * unprocessed-events queue says WHY rather than just how many —
             * and the row survives even if every retry is exhausted.
             */
            $event->forceFill(['last_error' => $exception->getMessage()])->save();

            throw $exception;
        }
    }

    /**
     * Route the event to whatever owns that kind of money.
     *
     * Unknown events are marked processed rather than failed. Lenco adds
     * event types over time, and retrying forever on one we have no handler
     * for would fill the failed-jobs table with something harmless.
     */
    private function dispatchEvent(
        LencoWebhookEvent $event,
        CollectionService $collections,
        PayoutService $payouts,
        RefundService $refunds,
    ): void {
        $reference = $event->reference;

        if ($reference === null) {
            return;
        }

        match ($event->event) {
            /* Authoritative for money in — but still verified server-side. */
            'collection.successful' => $collections->settle($reference, PaymentSource::Webhook),
            'collection.failed' => $collections->fail($reference, PaymentSource::Webhook),
            'collection.settled' => $collections->verify($reference, PaymentSource::Webhook),

            'transfer.successful', 'transfer.failed' => $this->handleTransfer($reference, $payouts, $refunds),

            default => null,
        };
    }

    /**
     * A transfer reference is either a payout line or a refund.
     *
     * Told apart by the prefix the reference was built with — PO- or RF- —
     * rather than by looking in two tables, so an event for something that no
     * longer exists is a no-op instead of a query that finds nothing twice.
     */
    private function handleTransfer(string $reference, PayoutService $payouts, RefundService $refunds): void
    {
        if (str_starts_with($reference, 'RF-')) {
            $refunds->syncFromGateway($reference);

            return;
        }

        if (str_starts_with($reference, 'PO-')) {
            $payouts->syncLineFromGateway($reference);
        }
    }
}
