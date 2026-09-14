<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Integrations\Payments\Exceptions\LencoRequestFailed;
use App\Models\User;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Payments\Enums\PaymentChannel;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Jobs\SendRefund;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Money going back to a buyer.
 *
 * ## Where the ledger entry comes from — read this first
 *
 * Refunds have TWO owners and the split is deliberate. Ledger decides what a
 * refund does to the accounts; Payments decides how the cash physically gets
 * back to the buyer. They must not both post.
 *
 * - A refund from a DISPUTE is already posted by Ledger's
 *   `PostDisputeResolution`, which listens to the same event this service
 *   does. So `createForDispute()` moves money and posts nothing. Posting here
 *   as well would refund the books twice for one decision.
 * - A refund from a cancellation or an administrator has no such listener, so
 *   `createForOrder()` posts it itself, with an event key naming the refund
 *   row — unique per refund, so a retried job posts once while a genuine
 *   second refund on the same order posts again.
 *
 * ## Card refunds cannot be done by code
 *
 * Lenco reverses card payments out of band, so there is no API call to make.
 * Those refunds are created `manual`, land in the Finance queue, and wait for
 * a person. Modelling them as transfers that keep failing would leave a buyer
 * waiting a fortnight while a job retried something that could never work.
 *
 * ## Refunds follow the money back
 *
 * The destination is copied off the original Payment, never off the buyer's
 * current profile. A buyer who changed their phone number last week must not
 * be able to redirect a refund for a payment made from the old one.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderPostingService $posting,
    ) {}

    /**
     * Raise a refund from a dispute resolution.
     *
     * Posts NO ledger entry — Ledger's own listener does that. See the class
     * docblock.
     */
    public function createForDispute(OrderDispute $dispute, Money $amount, ?User $actor = null): ?Refund
    {
        $order = $dispute->order;

        return $this->create(
            order: $order,
            amount: $amount,
            reason: RefundReason::Dispute,
            actor: $actor,
            dispute: $dispute,
            postLedger: false,
        );
    }

    /**
     * Raise a refund for a cancellation or an administrator's decision.
     *
     * Posts the ledger entry itself.
     */
    public function createForOrder(
        Order $order,
        Money $amount,
        RefundReason $reason,
        ?User $actor = null,
        ?string $note = null,
    ): ?Refund {
        return $this->create($order, $amount, $reason, $actor, null, true, $note);
    }

    /**
     * The shared path.
     *
     * @throws \InvalidArgumentException
     */
    private function create(
        Order $order,
        Money $amount,
        RefundReason $reason,
        ?User $actor = null,
        ?OrderDispute $dispute = null,
        bool $postLedger = true,
        ?string $note = null,
    ): ?Refund {
        $amount = $amount->absolute();

        if (! $amount->isPositive()) {
            return null;
        }

        $payment = Payment::settlementFor($order->group);
        $channel = $payment instanceof Payment ? ($payment->channel ?? PaymentChannel::Unknown) : PaymentChannel::Unknown;

        $refund = DB::transaction(function () use (
            $order, $amount, $reason, $actor, $dispute, $postLedger, $note, $payment, $channel
        ): Refund {
            $sequence = Refund::query()->where('order_id', $order->getKey())->count() + 1;
            $destination = $payment?->destination() ?? [];

            $refund = Refund::create([
                'order_id' => $order->getKey(),
                'user_id' => $order->user_id,
                'order_dispute_id' => $dispute?->getKey(),
                'reference' => Refund::referenceFor($order, $sequence),
                'status' => RefundStatus::Pending,
                'method' => RefundMethod::forChannel($channel),
                'reason_code' => $reason,
                'amount_ngwee' => $amount,
                'destination_phone' => $destination['phone'] ?? null,
                'destination_network' => $destination['network'] ?? null,
                'destination_account' => $destination['account_number'] ?? null,
                'destination_bank_code' => $destination['bank_code'] ?? null,
                'created_by' => $actor?->getKey(),
                'notes' => $note,
            ]);

            if ($postLedger) {
                $entries = $this->posting->refund(
                    order: $order,
                    amount: $amount,
                    eventKey: 'refund:'.$refund->reference,
                    actor: $actor,
                    reason: $reason->label(),
                );

                $entry = $entries[0] ?? null;

                if ($entry !== null) {
                    $refund->forceFill(['journal_entry_id' => $entry->getKey()])->save();
                }
            }

            /*
             * A refund that cannot be sent — a card payment, or a wallet we
             * have no number for — is parked for Finance immediately rather
             * than dispatched into a job that can only fail.
             */
            if (! $refund->method->isAutomatic() || ! $refund->hasUsableDestination()) {
                $refund->forceFill(['status' => RefundStatus::Manual, 'method' => RefundMethod::CardManual])->save();
            }

            return $refund->refresh();
        });

        audit($actor, 'payments.refund.raised', $refund, null, [
            'order' => $order->number,
            'amount_ngwee' => $amount->ngwee,
            'method' => $refund->method->value,
            'status' => $refund->status->value,
            'reason' => $reason->value,
        ], $note);

        if ($refund->status === RefundStatus::Pending) {
            SendRefund::dispatch($refund->getKey());
        }

        return $refund;
    }

    /**
     * Actually send an automatic refund.
     *
     * Same "unknown is not failed" rule as payouts: a transfer that threw may
     * have moved money, so it is parked for Finance rather than retried.
     */
    public function send(Refund $refund): Refund
    {
        if ($refund->status !== RefundStatus::Pending) {
            return $refund;
        }

        try {
            $transfer = $this->gateway->initiateTransfer(
                $refund->amount_ngwee,
                $refund->reference,
                $refund->recipientPayload(),
                ['order_id' => $refund->order_id, 'refund' => $refund->reference],
            );
        } catch (LencoRequestFailed $exception) {
            $refund->forceFill([
                'status' => RefundStatus::Manual,
                'failure_reason' => $exception->getMessage(),
            ])->save();

            audit(null, 'payments.refund.needs_manual', $refund, null, [
                'reason' => $exception->getMessage(),
            ]);

            return $refund->refresh();
        }

        $refund->forceFill([
            'status' => RefundStatus::Sent,
            'lenco_transfer_id' => $transfer->gatewayId,
            'sent_at' => now(),
        ])->save();

        if ($transfer->status === PaymentStatus::Successful) {
            return $this->markCompleted($refund->refresh());
        }

        if ($transfer->status === PaymentStatus::Failed) {
            return $this->markFailed($refund->refresh(), $transfer->failureReason ?? __('The transfer was rejected.'));
        }

        return $refund->refresh();
    }

    /**
     * A transfer webhook came back about a refund.
     */
    public function syncFromGateway(string $reference): ?Refund
    {
        $refund = Refund::query()->where('reference', $reference)->first();

        if ($refund === null || $refund->status->isFinal()) {
            return $refund;
        }

        $transfer = $this->gateway->fetchTransfer($reference);

        return match ($transfer->status) {
            PaymentStatus::Successful => $this->markCompleted($refund),
            PaymentStatus::Failed, PaymentStatus::Reversed => $this->markFailed(
                $refund,
                $transfer->failureReason ?? __('The transfer was rejected.'),
            ),
            PaymentStatus::Pending => $refund,
        };
    }

    /**
     * The buyer has their money.
     */
    public function markCompleted(Refund $refund, ?User $actor = null): Refund
    {
        if ($refund->status === RefundStatus::Completed) {
            return $refund;
        }

        $refund->forceFill([
            'status' => RefundStatus::Completed,
            'processed_by' => $actor?->getKey() ?? $refund->processed_by,
            'completed_at' => now(),
        ])->save();

        audit($actor, 'payments.refund.completed', $refund, null, [
            'amount_ngwee' => $refund->amount_ngwee->ngwee,
            'order_id' => $refund->order_id,
        ]);

        return $refund->refresh();
    }

    /**
     * The transfer bounced.
     *
     * The ledger is NOT reversed. The platform still owes the buyer this
     * money — only the delivery of it failed — so the refund stays owed and
     * moves to the Finance queue to be sent another way.
     */
    public function markFailed(Refund $refund, string $reason): Refund
    {
        $refund->forceFill([
            'status' => RefundStatus::Failed,
            'failure_reason' => $reason,
            'failed_at' => now(),
        ])->save();

        audit(null, 'payments.refund.failed', $refund, null, [
            'amount_ngwee' => $refund->amount_ngwee->ngwee,
            'reason' => $reason,
        ], $reason);

        return $refund->refresh();
    }

    /**
     * Finance has put a manual refund through Lenco's card process by hand.
     */
    public function completeManually(Refund $refund, User $actor, ?string $note = null): Refund
    {
        $before = ['status' => $refund->status->value];

        $refund->forceFill([
            'status' => RefundStatus::Completed,
            'processed_by' => $actor->getKey(),
            'completed_at' => now(),
            'notes' => $note ?? $refund->notes,
        ])->save();

        audit($actor, 'payments.refund.completed_manually', $refund, $before, [
            'status' => RefundStatus::Completed->value,
            'amount_ngwee' => $refund->amount_ngwee->ngwee,
        ], $note);

        return $refund->refresh();
    }
}
