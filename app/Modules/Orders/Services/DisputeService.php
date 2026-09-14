<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Orders\Enums\DisputeReason;
use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Enums\DisputeStatus;
use App\Modules\Orders\Events\DisputeOpened;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Orders\Exceptions\DisputeNotAllowed;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Orders\Notifications\DisputeUpdatedNotification;
use App\Modules\Orders\Support\OrderActor;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A buyer's objection, from raising it to settling it.
 *
 * The hold is the whole point. Opening a dispute moves the order to Disputed
 * and clears its auto-complete deadline inside one transaction, so that the
 * sweep which would otherwise complete the order three days after handover
 * finds nothing to do. Doing that through a queued listener instead would
 * mean the hold fails precisely when the queue is backed up, which is exactly
 * when a platform is busy enough for it to matter.
 *
 * Resolution is the mirror image: the moderator's decision is an instruction
 * to the money, so it moves the order to where the resolution says it belongs
 * and fires DisputeResolved carrying the amount. Payments (Prompt 09) acts on
 * that and nothing else — a ledger cannot post "found for the buyer".
 */
class DisputeService
{
    public function __construct(private readonly OrderStateMachine $orders) {}

    /**
     * The buyer raises a problem.
     *
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws DisputeNotAllowed
     */
    public function open(
        Order $order,
        User $buyer,
        DisputeReason $reason,
        string $details,
        array $photos = [],
    ): OrderDispute {
        if (! $order->status->isPaid()) {
            throw DisputeNotAllowed::notPaid($order);
        }

        if (! $order->status->allowsDispute()) {
            throw DisputeNotAllowed::orderFinished($order);
        }

        if ($order->hasOpenDispute()) {
            throw DisputeNotAllowed::alreadyOpen($order);
        }

        $dispute = DB::transaction(function () use ($order, $buyer, $reason, $details): OrderDispute {
            $dispute = OrderDispute::query()->create([
                'order_id' => $order->getKey(),
                'opened_by' => $buyer->getKey(),
                'reason' => $reason,
                'details' => $details,
                'status' => DisputeStatus::Open,
            ]);

            /*
             * Inside the transaction, so an order can never be sitting in
             * Disputed without a dispute row or the other way round. The
             * state machine clears auto_complete_at as it goes.
             */
            $this->orders->dispute(
                $order,
                OrderActor::forUser($buyer, $order),
                $reason->label(),
            );

            return $dispute;
        });

        /*
         * Photographs go on after the transaction commits. Media Library
         * writes to disk, and a failed upload must not roll back a hold the
         * buyer has already been told is in place — a dispute with no photos
         * is still a dispute.
         */
        foreach ($photos as $photo) {
            $dispute->addMedia($photo)->toMediaCollection('evidence');
        }

        audit(
            $buyer,
            'dispute.opened',
            $dispute,
            null,
            ['reason' => $reason->value, 'order' => $order->number],
            $details,
        );

        DisputeOpened::dispatch($dispute);

        return $dispute;
    }

    /**
     * A moderator picks the dispute up.
     *
     * Separate from resolving it so the queue can show what is being looked
     * at and what nobody has touched — which is the difference between a
     * backlog and a list.
     */
    public function claim(OrderDispute $dispute, User $staff): OrderDispute
    {
        if (! $dispute->isOpen()) {
            throw DisputeNotAllowed::alreadyResolved($dispute->order);
        }

        $before = $dispute->status;

        $dispute->update(['status' => DisputeStatus::UnderReview]);

        audit(
            $staff,
            'dispute.claimed',
            $dispute,
            ['status' => $before->value],
            ['status' => DisputeStatus::UnderReview->value],
        );

        /*
         * "Somebody is looking at it" is the single most useful thing to say
         * to a person waiting on a dispute, and the absence of it is what
         * makes them ring support to ask. Both sides hear it, and neither is
         * told anything the other is not.
         */
        $this->notifyParties($dispute, 'A MonaFind moderator is now reviewing this dispute.');

        return $dispute;
    }

    /**
     * The moderator decides.
     *
     * @param  Money|null  $amount  Required for a partial refund; ignored otherwise.
     *
     * @throws DisputeNotAllowed
     */
    public function resolve(
        OrderDispute $dispute,
        DisputeResolution $resolution,
        User $staff,
        ?Money $amount = null,
        ?string $note = null,
    ): OrderDispute {
        if (! $dispute->isOpen()) {
            throw DisputeNotAllowed::alreadyResolved($dispute->order);
        }

        $order = $dispute->order;
        $refund = $resolution->refundAmount($order->total_ngwee, $amount);

        /*
         * A partial refund larger than the order is not a partial refund. A
         * moderator typing one has made a mistake, and clamping is kinder
         * than a validation error at the end of a phone call — but it is
         * clamped rather than accepted, because the ledger will post exactly
         * this figure.
         */
        if ($refund->greaterThan($order->total_ngwee)) {
            $refund = $order->total_ngwee;
        }

        $before = ['status' => $dispute->status->value, 'order_status' => $order->status->value];

        DB::transaction(function () use ($dispute, $order, $resolution, $refund, $staff, $note): void {
            $dispute->update([
                'status' => DisputeStatus::Resolved,
                'resolution' => $resolution,
                'refund_amount_ngwee' => $refund,
                'resolution_note' => $note,
                'resolved_by' => $staff->getKey(),
                'resolved_at' => now(),
            ]);

            $order->forceFill(['refunded_amount_ngwee' => $refund])->save();

            $this->orders->transition(
                $order->refresh(),
                $resolution->resultingStatus(),
                OrderActor::forUser($staff, $order),
                $note ?? $resolution->label(),
                ['dispute_id' => $dispute->getKey(), 'refund_ngwee' => $refund->ngwee],
            );
        });

        audit(
            $staff,
            'dispute.resolved',
            $dispute,
            $before,
            [
                'status' => DisputeStatus::Resolved->value,
                'resolution' => $resolution->value,
                'refund_ngwee' => $refund->ngwee,
            ],
            $note,
        );

        DisputeResolved::dispatch($dispute->refresh(), $resolution, $refund, $staff->getKey());

        return $dispute;
    }

    /**
     * Whether this order is one the buyer could still raise a problem with.
     *
     * Used by the pages to decide whether to render the button at all, so
     * that the refusal in open() is a guard rather than something a buyer
     * runs into.
     */
    /**
     * Tell the buyer and the seller the same thing about their dispute.
     */
    private function notifyParties(OrderDispute $dispute, string $summary): void
    {
        $order = $dispute->order;

        $order->buyer->notify(new DisputeUpdatedNotification($dispute, $summary));
        $order->seller->user->notify(new DisputeUpdatedNotification($dispute, $summary));
    }

    public function isOpenable(Order $order): bool
    {
        return $order->status->isPaid()
            && $order->status->allowsDispute()
            && ! $order->hasOpenDispute();
    }

    /**
     * Orders whose auto-completion is being held up right now.
     *
     * @return Collection<int, OrderDispute>
     */
    public function queue(?DisputeStatus $status = null): Collection
    {
        return OrderDispute::query()
            ->with(['order.seller', 'order.buyer', 'opener'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($status === null, fn ($query) => $query->open())
            ->oldest('created_at')
            ->get();
    }

    /**
     * A seller's dispute rate over a trailing window, as a percentage.
     *
     * The figure that decides whether a direct-payment seller keeps direct
     * payment. Counted against orders that reached payment, because an order
     * nobody paid for cannot be disputed and including it would flatter every
     * seller who gets a lot of abandoned checkouts.
     */
    public function disputeRateFor(int $sellerId, int $trailingDays): float
    {
        $since = now()->subDays($trailingDays);

        $paid = Order::query()
            ->where('seller_id', $sellerId)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->count();

        if ($paid === 0) {
            return 0.0;
        }

        $disputed = Order::query()
            ->where('seller_id', $sellerId)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->whereHas('disputes')
            ->count();

        return round(($disputed / $paid) * 100, 2);
    }
}
