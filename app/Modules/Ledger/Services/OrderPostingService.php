<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Models\User;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Support\MonetisationBreakdown;
use App\Modules\Ledger\Support\Posting;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The recipes: what each thing that happens to an order does to the accounts.
 *
 * Pure ledger and gateway-agnostic on purpose. Nothing here knows Lenco
 * exists, which is what lets every money path be tested offline and in full
 * before a single API call is written — and, later, lets the gateway be
 * swapped without reopening the accounting.
 *
 * ## The two modes
 *
 * Under ESCROW the platform holds the buyer's money: payment moves cash into
 * a liability owed to nobody in particular yet, and it is only on completion
 * that the liability splits into what the seller is owed and what MonaFind
 * earned. An order refunded before completion never touches revenue at all,
 * which is exactly right — the platform earned nothing.
 *
 * Under DIRECT the split happens at payment, less a rolling reserve held
 * against the disputes that mode invites. There is no escrow leg because
 * there is nothing to hold.
 *
 * Which mode applies is read from the ORDER, never from the seller. The mode
 * is snapshotted at payment time, so a seller moved to direct settlement this
 * morning does not have last week's escrow released early.
 *
 * ## Refunds
 *
 * Every refund figure is derived by working out what the order looks like
 * with less money in it and subtracting: the platform's take on the retained
 * value, against its take on the value before. Nothing is scaled by a
 * percentage of a percentage, so no ngwee is lost between the buyer's refund
 * and the four accounts it comes out of.
 */
class OrderPostingService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly LedgerBalances $balances,
        private readonly PolicyCalculator $calculator,
        private readonly CommissionInvoiceService $invoices,
    ) {}

    /**
     * The buyer's money arrived. Post it by the mode the order carries.
     */
    public function recordPayment(Order $order, ?string $reference = null): JournalEntry
    {
        return $this->modeFor($order) === PaymentMode::Direct
            ? $this->postDirectPayment($order, $reference)
            : $this->postEscrowPayment($order, $reference);
    }

    /**
     * ESCROW payment — Dr platform_cash / Cr escrow_held(order).
     *
     * Deliberately touches no revenue account. The platform is holding money
     * it may well have to give straight back, and recognising commission on
     * it would mean reversing revenue on every cancelled order.
     */
    public function postEscrowPayment(Order $order, ?string $reference = null): JournalEntry
    {
        $gross = $order->total_ngwee;

        return $this->ledger->post(
            Posting::make(
                PostingRecipe::EscrowPayment,
                'Payment held in escrow for order '.$order->number.'.',
                $this->key('escrow-payment', $order),
            )
                ->about($order)
                ->at($order->paid_at)
                ->withContext(['reference' => $reference, 'order' => $order->number])
                ->debit(LedgerAccountCode::PlatformCash, $gross)
                ->credit(LedgerAccountCode::EscrowHeld, $gross, $order, 'Held for order '.$order->number),
        );
    }

    /**
     * DIRECT payment — cash in, and the split done immediately.
     *
     * Dr platform_cash / Cr seller_payable(net less reserve), seller_reserve,
     * commission_revenue, addon_revenue, referral_revenue and the VAT owed on
     * the commission. The reserve reduces what is payable now rather than
     * being an extra charge: the seller is owed it, just not yet.
     */
    public function postDirectPayment(Order $order, ?string $reference = null): JournalEntry
    {
        $seller = $order->seller;
        $breakdown = $this->calculator->forOrder($order, withholdReserve: true);

        $entry = $this->ledger->post(
            $this->creditSplit(
                Posting::make(
                    PostingRecipe::DirectPayment,
                    'Direct settlement of order '.$order->number.'.',
                    $this->key('direct-payment', $order),
                )
                    ->about($order)
                    ->at($order->paid_at)
                    ->withContext(['reference' => $reference, 'order' => $order->number, ...$breakdown->toArray()])
                    ->debit(LedgerAccountCode::PlatformCash, $breakdown->gross),
                $breakdown,
                $seller,
                $order,
            ),
        );

        $this->invoices->issueFor($order, $breakdown, $entry);

        return $entry;
    }

    /**
     * The order is finished — ESCROW release.
     *
     * Dr escrow_held / Cr seller_payable(net) and the revenue and VAT lines.
     * This is the moment MonaFind's commission becomes revenue, which is why
     * it is also the moment the commission invoice is raised.
     *
     * Releases whatever is STILL held rather than the order's face value, so
     * a partial refund taken out earlier simply leaves less to release and
     * the platform's take is worked out on what the buyer actually kept.
     * Returns null when there is nothing held: a fully refunded order, or a
     * direct-settlement one that never had an escrow leg.
     */
    public function releaseEscrow(Order $order, ?User $actor = null): ?JournalEntry
    {
        $held = $this->balances->escrowHeldFor($order);

        if (! $held->isPositive()) {
            return null;
        }

        $seller = $order->seller;
        $breakdown = $this->retained($order, $order->total_ngwee->minus($held), withholdReserve: false);

        $entry = $this->ledger->post(
            $this->creditSplit(
                Posting::make(
                    PostingRecipe::EscrowRelease,
                    'Escrow released on order '.$order->number.'.',
                    $this->key('escrow-release', $order),
                )
                    ->about($order)
                    ->by($actor)
                    ->withContext(['order' => $order->number, ...$breakdown->toArray()])
                    ->debit(LedgerAccountCode::EscrowHeld, $held, $order, 'Released on order '.$order->number),
                $breakdown,
                $seller,
                $order,
            ),
        );

        $this->invoices->issueFor($order, $breakdown, $entry);

        return $entry;
    }

    /**
     * Money goes back to the buyer.
     *
     * Comes out of escrow while there is escrow to come out of, and is
     * clawed back from the seller when there is not — which covers both a
     * direct-settlement seller, who never had an escrow leg, and an escrow
     * order refunded after it had already been released.
     *
     * The event key is the caller's to supply, because only the caller knows
     * what the business event was: a dispute resolution, a cancellation, an
     * administrator's decision. Two refunds of the same amount on the same
     * order are two different events and must post twice; the same dispute
     * resolution arriving twice is one event and must post once.
     *
     * @return array<int, JournalEntry> The entries posted, in order.
     */
    public function refund(
        Order $order,
        Money $amount,
        string $eventKey,
        ?User $actor = null,
        ?string $reason = null,
    ): array {
        /*
         * Never give back more than the order was worth, however many times
         * it is refunded. Without this a second refund would post lines the
         * order has no value left to support, and the clawback arithmetic —
         * which works by difference — would silently produce nothing while
         * the cash line still moved.
         */
        $remaining = $order->total_ngwee->minus($this->refundedSoFar($order));
        $amount = Money::min($amount->absolute(), Money::max($remaining, Money::zero()));

        if (! $amount->isPositive()) {
            return [];
        }

        return DB::transaction(function () use ($order, $amount, $eventKey, $actor, $reason): array {
            $entries = [];
            $fromEscrow = Money::min($amount, $this->balances->escrowHeldFor($order));

            if ($fromEscrow->isPositive()) {
                $entries[] = $this->postEscrowRefund($order, $fromEscrow, $eventKey, $actor, $reason);
            }

            $clawback = $amount->minus($fromEscrow);

            if ($clawback->isPositive()) {
                $entries[] = $this->postClawback($order, $clawback, $eventKey, $actor, $reason);
            }

            return $entries;
        });
    }

    /**
     * Refund out of what is held — Dr escrow_held / Cr platform_cash.
     *
     * Nothing else moves, because nothing else ever happened: money came in,
     * was held, and went back out. No revenue to reverse, no seller to
     * recover from.
     */
    private function postEscrowRefund(
        Order $order,
        Money $amount,
        string $eventKey,
        ?User $actor,
        ?string $reason,
    ): JournalEntry {
        return $this->ledger->post(
            Posting::make(
                PostingRecipe::EscrowRefund,
                'Refund from escrow on order '.$order->number.'.',
                $eventKey.':escrow-refund',
            )
                ->about($order)
                ->by($actor)
                ->withContext(['order' => $order->number, 'reason' => $reason])
                ->debit(LedgerAccountCode::EscrowHeld, $amount, $order, 'Refunded to buyer')
                ->credit(LedgerAccountCode::PlatformCash, $amount),
        );
    }

    /**
     * Refund money the seller has already been credited with.
     *
     * Everybody who was paid gives back their share: MonaFind reverses the
     * commission, add-on, referral and VAT that the refunded part of the
     * order earned, and the seller carries the rest. The seller's payable
     * goes negative if they have already been paid out, which is the correct
     * reading — they owe it, and it nets off against their next sales.
     *
     * The shares are derived by difference rather than by scaling, so they
     * add up to the refund exactly.
     */
    private function postClawback(
        Order $order,
        Money $amount,
        string $eventKey,
        ?User $actor,
        ?string $reason,
    ): JournalEntry {
        $seller = $order->seller;
        $withholdReserve = $this->modeFor($order) === PaymentMode::Direct;

        $refundedBefore = $this->refundedSoFar($order);
        $before = $this->retained($order, $refundedBefore, $withholdReserve);
        $after = $this->retained($order, $refundedBefore->plus($amount), $withholdReserve);

        $posting = Posting::make(
            PostingRecipe::DirectClawback,
            'Refund recovered on order '.$order->number.'.',
            $eventKey.':clawback',
        )
            ->about($order)
            ->by($actor)
            ->withContext(['order' => $order->number, 'reason' => $reason, 'retained' => $after->toArray()])
            ->debitIfAny(LedgerAccountCode::CommissionRevenue, $before->commission->minus($after->commission))
            ->debitIfAny(LedgerAccountCode::AddonRevenue, $before->addonFee->minus($after->addonFee))
            ->debitIfAny(LedgerAccountCode::ReferralRevenue, $before->referralFee->minus($after->referralFee))
            ->debitIfAny(LedgerAccountCode::VatOnCommissionPayable, $before->vatOnCommission->minus($after->vatOnCommission))
            ->debitIfAny(LedgerAccountCode::SellerReserve, $before->reserve->minus($after->reserve), $seller, 'Reserve released against refund')
            ->debitIfAny(
                LedgerAccountCode::SellerPayable,
                $before->payableToSeller->minus($after->payableToSeller),
                $seller,
                'Recovered on order '.$order->number,
            )
            ->credit(LedgerAccountCode::PlatformCash, $amount);

        return $this->ledger->post($posting);
    }

    /**
     * A refund MonaFind paid for itself.
     *
     * Dr refunds_expense / Cr platform_cash. The seller keeps their money and
     * the platform absorbs the cost — a goodwill decision, and the only kind
     * of refund that touches the expense account. It is separated from the
     * others so that "what did goodwill cost us this month" is one query
     * rather than a judgement about which refunds were which.
     */
    public function postGoodwillRefund(
        Order $order,
        Money $amount,
        string $eventKey,
        ?User $actor = null,
        ?string $reason = null,
    ): JournalEntry {
        return $this->ledger->post(
            Posting::make(
                PostingRecipe::GoodwillRefund,
                'Goodwill refund on order '.$order->number.'.',
                $eventKey.':goodwill-refund',
            )
                ->about($order)
                ->by($actor)
                ->withContext(['order' => $order->number, 'reason' => $reason])
                ->debit(LedgerAccountCode::RefundsExpense, $amount)
                ->credit(LedgerAccountCode::PlatformCash, $amount),
        );
    }

    /**
     * A seller was paid.
     *
     * Dr seller_payable / Cr platform_cash, with the gateway's charge as a
     * separate debit to lenco_fees_expense. The fee is MonaFind's cost, not a
     * deduction from the seller: the seller is owed what they are owed, and
     * what it costs to hand it over is the platform's business.
     *
     * Takes the payout's own model as the reference so that Payments can pass
     * its Payout row when that module lands, without this signature changing.
     */
    public function recordPayout(
        Seller $seller,
        Money $amount,
        string $eventKey,
        ?Money $fee = null,
        ?Model $reference = null,
        ?User $actor = null,
    ): JournalEntry {
        $fee ??= Money::zero();

        return $this->ledger->post(
            Posting::make(
                PostingRecipe::Payout,
                'Payout to '.$seller->business_name.'.',
                $eventKey,
            )
                ->about($reference)
                ->by($actor)
                ->withContext(['seller_id' => $seller->getKey(), 'fee_ngwee' => $fee->ngwee])
                ->debit(LedgerAccountCode::SellerPayable, $amount, $seller, 'Paid out')
                ->debitIfAny(LedgerAccountCode::LencoFeesExpense, $fee, null, 'Gateway charge on payout')
                ->credit(LedgerAccountCode::PlatformCash, $amount->plus($fee)),
        );
    }

    /**
     * A held reserve becomes payable again.
     *
     * Dr seller_reserve / Cr seller_payable. No cash moves — the money has
     * been the platform's to hold and the seller's to receive all along; this
     * only says the holding period is over.
     */
    public function releaseReserve(
        Seller $seller,
        Money $amount,
        string $eventKey,
        ?User $actor = null,
    ): ?JournalEntry {
        if (! $amount->isPositive()) {
            return null;
        }

        return $this->ledger->post(
            Posting::make(
                PostingRecipe::ReserveRelease,
                'Reserve released to '.$seller->business_name.'.',
                $eventKey,
            )
                ->by($actor)
                ->about($seller)
                ->withContext(['seller_id' => $seller->getKey()])
                ->debit(LedgerAccountCode::SellerReserve, $amount, $seller, 'Reserve period elapsed')
                ->credit(LedgerAccountCode::SellerPayable, $amount, $seller, 'Reserve released'),
        );
    }

    /**
     * The credit side shared by the two recipes that recognise revenue.
     *
     * Written once because the two must agree: if a direct payment and an
     * escrow release split the same order differently, the platform's revenue
     * depends on which mode a seller happened to be on rather than on what
     * was sold.
     */
    private function creditSplit(
        Posting $posting,
        MonetisationBreakdown $breakdown,
        Seller $seller,
        Order $order,
    ): Posting {
        return $posting
            ->creditIfAny(LedgerAccountCode::SellerPayable, $breakdown->payableToSeller, $seller, 'Order '.$order->number)
            ->creditIfAny(LedgerAccountCode::SellerReserve, $breakdown->reserve, $seller, 'Reserve on order '.$order->number)
            ->creditIfAny(LedgerAccountCode::CommissionRevenue, $breakdown->commission)
            ->creditIfAny(LedgerAccountCode::AddonRevenue, $breakdown->addonFee)
            ->creditIfAny(LedgerAccountCode::ReferralRevenue, $breakdown->referralFee)
            ->creditIfAny(LedgerAccountCode::VatOnCommissionPayable, $breakdown->vatOnCommission);
    }

    /**
     * What the order looks like with $refunded taken out of it.
     *
     * Refunds come off the goods first and the delivery fee last, which is
     * how a part-refunded order is normally settled: the buyer keeps some of
     * what they bought and the courier was still paid. It also means a full
     * refund reduces everything to zero, whichever order the arithmetic runs
     * in.
     */
    private function retained(Order $order, Money $refunded, bool $withholdReserve): MonetisationBreakdown
    {
        $refunded = Money::max($refunded, Money::zero());

        $refundedGoods = Money::min($refunded, $order->items_total_ngwee);
        $refundedDelivery = Money::min($refunded->minus($refundedGoods), $order->delivery_fee_ngwee);

        return $this->calculator->forOrder(
            $order,
            withholdReserve: $withholdReserve,
            goods: $order->items_total_ngwee->minus($refundedGoods),
            delivery: $order->delivery_fee_ngwee->minus($refundedDelivery),
        );
    }

    /**
     * How much of this order has already gone back to the buyer.
     *
     * Read off the ledger rather than off `orders.refunded_amount_ngwee`.
     * The ledger is what the next posting has to be consistent with, and a
     * column maintained by another module is one deployment away from
     * disagreeing with it.
     */
    private function refundedSoFar(Order $order): Money
    {
        return Money::ofNgwee((int) JournalEntry::query()
            ->about($order)
            ->whereIn('recipe', [
                PostingRecipe::EscrowRefund->value,
                PostingRecipe::DirectClawback->value,
            ])
            ->sum('total_ngwee'));
    }

    /**
     * The mode this ORDER settles under — from its snapshot, never from the
     * seller's current setting.
     */
    private function modeFor(Order $order): PaymentMode
    {
        return $order->payment_mode ?? PaymentMode::Escrow;
    }

    /**
     * The idempotency key for a once-per-order event.
     */
    private function key(string $event, Order $order): string
    {
        return $event.':order:'.$order->getKey();
    }
}
