<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Enums;

use App\Modules\Orders\Models\Order;

/**
 * What entitled somebody to leave a rating.
 *
 * A rating on this platform is never an opinion volunteered out of the air.
 * It is attached to a thing that happened between two parties — an order that
 * completed, or a mechanic endorsement — and that thing is what the
 * uniqueness rule counts against. Without it, "one rating per counter-party"
 * has nothing to be one of.
 */
enum RatingSource: string
{
    /** A completed order. Both sides may rate; the buyer's review is a verified purchase. */
    case Order = 'order';

    /**
     * A mechanic endorsement.
     *
     * The Mechanics module owns the endorsement record itself and is not
     * built yet, which is why nothing here names its class: the source is a
     * polymorphic reference and Ratings only ever asks the
     * RatingSourceGuard bound for a source type whether it is finished.
     */
    case Endorsement = 'endorsement';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Order',
            self::Endorsement => 'Endorsement',
        };
    }

    /**
     * Whether a rating from this source carries the verified-purchase label.
     *
     * Money changed hands or it did not. An endorsement is a real
     * relationship but it is not a purchase, and labelling it one would make
     * the badge mean nothing.
     */
    public function isVerifiedPurchase(): bool
    {
        return $this === self::Order;
    }

    /**
     * The model class a source of this kind points at, where Ratings knows
     * it. Null for Endorsement until the Mechanics module lands.
     *
     * @return class-string|null
     */
    public function modelClass(): ?string
    {
        return match ($this) {
            self::Order => Order::class,
            self::Endorsement => null,
        };
    }
}
