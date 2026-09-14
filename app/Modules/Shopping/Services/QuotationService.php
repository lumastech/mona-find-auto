<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Enums\QuotationStatus;
use App\Modules\Shopping\Events\QuotationAccepted;
use App\Modules\Shopping\Events\QuotationAnswered;
use App\Modules\Shopping\Events\QuotationRequested;
use App\Modules\Shopping\Exceptions\InvalidQuotationTransition;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Exceptions\QuotationNotAcceptable;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Models\Quotation;
use App\Support\Money\Money;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The request-for-quotation lifecycle: open → quoted → accepted, or expired.
 *
 * This is the only thing that writes Quotation::$status, and every move is
 * checked against QuotationStatus::allowedTransitions() before it is made.
 * The alternative — each controller nudging the column — is how a quote ends
 * up accepted twice, or accepted after it expired, which on this platform
 * means a seller being held to a price they stopped offering.
 *
 * Authorisation is not here. Whether a particular seller may answer a
 * particular request is QuotationPolicy's question, asked by the controllers;
 * this class assumes the answer was yes and concerns itself with whether the
 * move is legal at all.
 */
class QuotationService
{
    public function __construct(private readonly CartService $cart) {}

    /**
     * A buyer asks a shop what a quantity would cost.
     *
     * The listing has to be one they could otherwise buy — there is no point
     * negotiating over something that has been unpublished — but stock is
     * deliberately not required. Asking a breaker whether they can source ten
     * of something is exactly the conversation this feature is for.
     *
     * @throws ListingNotPurchasable
     */
    public function request(User $buyer, ProductVariant $variant, int $quantity, ?string $message = null): Quotation
    {
        $variant->loadMissing('product.seller');
        $product = $variant->product;

        if (! $product->isVisibleToBuyers()) {
            throw ListingNotPurchasable::unavailable($product);
        }

        $quotation = Quotation::query()->create([
            'user_id' => $buyer->getKey(),
            'seller_id' => $product->seller_id,
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'status' => QuotationStatus::Open,
            'quantity' => max(1, min($quantity, Quotation::MAX_QUANTITY)),
            'message' => $message,
        ]);

        QuotationRequested::dispatch($quotation);

        return $quotation;
    }

    /**
     * The seller answers: a price per unit, a date it stands until, and how
     * they would get it to the buyer.
     *
     * A validity date in the past is refused rather than accepted and
     * immediately swept — a quote that was never acceptable is a mistake in
     * the form, and telling the seller beats sending the buyer an offer that
     * is already dead.
     *
     * @throws InvalidQuotationTransition
     */
    public function quote(
        Quotation $quotation,
        Money $unitPrice,
        DateTimeInterface $validUntil,
        ?string $deliveryNote = null,
    ): Quotation {
        $this->guardTransition($quotation, QuotationStatus::Quoted);

        $validUntil = Carbon::instance($validUntil)->startOfDay();

        if ($validUntil->endOfDay()->isPast()) {
            throw InvalidQuotationTransition::validityInThePast($quotation);
        }

        $quotation->forceFill([
            'status' => QuotationStatus::Quoted,
            'quoted_unit_price_ngwee' => $unitPrice,
            'valid_until' => $validUntil,
            'delivery_note' => $deliveryNote,
            'quoted_at' => now(),
        ])->save();

        QuotationAnswered::dispatch($quotation);

        return $quotation;
    }

    /**
     * The buyer takes the price, and it becomes a cart line.
     *
     * The line carries the quotation id, which is what makes the quoted price
     * stick: CartService prices a quoted line off the offer rather than the
     * listing, and the id travels on to the order line so the money can be
     * traced back to what was agreed.
     *
     * Expiry is checked here and not merely trusted from the column, because
     * a quote expires by the passage of time — the sweep records that, it
     * does not cause it, and a buyer who opens a stale page must not be able
     * to accept from it.
     *
     * @throws QuotationNotAcceptable
     */
    public function accept(Quotation $quotation): CartItem
    {
        if ($quotation->status !== QuotationStatus::Quoted) {
            throw QuotationNotAcceptable::notQuoted($quotation);
        }

        if ($quotation->hasExpired()) {
            /* Write down what time already did, then refuse. */
            $this->expire($quotation);

            throw QuotationNotAcceptable::expired($quotation);
        }

        $quotation->loadMissing(['buyer', 'variant.product.seller']);

        $line = $this->cart->add(
            user: $quotation->buyer,
            variant: $quotation->variant,
            quantity: $quotation->quantity,
            quotation: $quotation,
        );

        $quotation->forceFill([
            'status' => QuotationStatus::Accepted,
            'accepted_at' => now(),
        ])->save();

        QuotationAccepted::dispatch($quotation, $line);

        return $line;
    }

    /**
     * The seller will not quote for this.
     *
     * @throws InvalidQuotationTransition
     */
    public function decline(Quotation $quotation, ?string $reason = null): Quotation
    {
        $this->guardTransition($quotation, QuotationStatus::Declined);

        $quotation->forceFill([
            'status' => QuotationStatus::Declined,
            'decline_reason' => $reason,
            'declined_at' => now(),
        ])->save();

        QuotationAnswered::dispatch($quotation);

        return $quotation;
    }

    /**
     * Record that a quote's day has passed.
     *
     * Idempotent, and quiet about requests that are already finished: the
     * sweep runs daily over a shared index and must not care whether it is
     * racing another copy of itself.
     */
    public function expire(Quotation $quotation): Quotation
    {
        if (! $quotation->status->canTransitionTo(QuotationStatus::Expired)) {
            return $quotation;
        }

        $quotation->forceFill([
            'status' => QuotationStatus::Expired,
            'expired_at' => now(),
        ])->save();

        return $quotation;
    }

    /**
     * Void every quote whose validity date has passed.
     *
     * @return int How many were voided.
     */
    public function expireStale(?DateTimeInterface $asOf = null): int
    {
        $expired = 0;

        Quotation::query()
            ->stale($asOf)
            ->cursor()
            ->each(function (Quotation $quotation) use (&$expired): void {
                $this->expire($quotation);

                $expired++;
            });

        return $expired;
    }

    /**
     * How many requests are sitting unanswered in a shop's inbox — the badge
     * on the seller portal.
     */
    public function awaitingSellerCount(Seller $seller): int
    {
        return Quotation::query()
            ->forSeller($seller)
            ->where('status', QuotationStatus::Open)
            ->count();
    }

    /**
     * @throws InvalidQuotationTransition
     */
    private function guardTransition(Quotation $quotation, QuotationStatus $to): void
    {
        if (! $quotation->status->canTransitionTo($to)) {
            throw InvalidQuotationTransition::between($quotation->status, $to);
        }
    }
}
