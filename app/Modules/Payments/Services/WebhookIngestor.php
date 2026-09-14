<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Contracts\PaymentGateway;
use App\Modules\Payments\Jobs\ProcessLencoWebhook;
use App\Modules\Payments\Models\LencoWebhookEvent;
use Illuminate\Database\QueryException;

/**
 * The front door for Lenco's webhooks.
 *
 * Deliberately does almost nothing: verify the signature, store the body,
 * queue the work. Everything that could be slow or could throw happens in the
 * job, because Lenco retries anything that is not a prompt 200 and a handler
 * that does real work inside the request turns one event into a retry storm.
 *
 * Storing before acting also means an event survives a worker crash. The row
 * is the receipt; the job is the work; the two are separate on purpose.
 */
class WebhookIngestor
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Whether this body really came from Lenco.
     *
     * An unsigned request is rejected outright rather than treated as an
     * empty signature, because "no header" and "wrong header" deserve the
     * same answer and neither should reach the database.
     */
    public function isAuthentic(string $rawBody, ?string $signature): bool
    {
        return $signature !== null
            && $signature !== ''
            && $this->gateway->verifyWebhookSignature($rawBody, $signature);
    }

    /**
     * Store a verified event and queue it, unless we already have it.
     *
     * Returns null for a redelivery. The caller answers 200 either way —
     * telling Lenco an event failed because we already had it would just make
     * them send it again.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingest(array $payload, string $rawBody): ?LencoWebhookEvent
    {
        try {
            $event = LencoWebhookEvent::create([
                'lenco_event_id' => LencoWebhookEvent::identify($payload, $rawBody),
                'event' => (string) ($payload['event'] ?? 'unknown'),
                'reference' => LencoWebhookEvent::referenceFrom($payload),
                'lenco_id' => $this->lencoIdFrom($payload),
                'payload' => $payload,
                'received_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicate($exception)) {
                throw $exception;
            }

            /* Seen it. Acknowledged, not queued again. */
            return null;
        }

        ProcessLencoWebhook::dispatch($event->getKey());

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function lencoIdFrom(array $payload): ?string
    {
        $id = $payload['data']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function isDuplicate(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }
}
