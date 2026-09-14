<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Enums;

/**
 * Who is rating whom.
 *
 * Five directions, and the split that matters runs straight through them:
 * three are public and two are not. A buyer's review of a shop is the
 * platform's most-read piece of content; a shop's rating of a buyer is a note
 * between traders about whether someone turns up, pays and does not invent
 * faults. Publishing the second would turn a marketplace into a place where
 * complaining about a seller costs you your ability to buy anywhere.
 *
 * So `isPublic()` is not a display preference. It is the rule every query,
 * every Inertia prop and every API response is filtered by, and it is
 * declared here once so that no controller gets to have an opinion about it.
 */
enum RatingDirection: string
{
    /** A buyer reviews the shop they bought from. Public. */
    case BuyerToSeller = 'buyer_to_seller';

    /** A buyer reviews a mechanic who worked for them. Public. */
    case BuyerToMechanic = 'buyer_to_mechanic';

    /** A shop rates the buyer. Visible to sellers, mechanics and staff only. */
    case SellerToBuyer = 'seller_to_buyer';

    /** A shop rates a mechanic it dealt with. Public. */
    case SellerToMechanic = 'seller_to_mechanic';

    /** A mechanic rates the buyer who engaged them. Not public. */
    case MechanicToBuyer = 'mechanic_to_buyer';

    public function label(): string
    {
        return match ($this) {
            self::BuyerToSeller => 'Buyer review of a seller',
            self::BuyerToMechanic => 'Buyer review of a mechanic',
            self::SellerToBuyer => 'Seller rating of a buyer',
            self::SellerToMechanic => 'Seller rating of a mechanic',
            self::MechanicToBuyer => 'Mechanic rating of a buyer',
        };
    }

    /**
     * Whether anybody may read this rating.
     *
     * The two that are not public are the two pointed at a buyer. They are
     * shown to sellers, to mechanics and to staff, because that is the whole
     * purpose of collecting them, and to nobody else.
     */
    public function isPublic(): bool
    {
        return match ($this) {
            self::BuyerToSeller, self::BuyerToMechanic, self::SellerToMechanic => true,
            self::SellerToBuyer, self::MechanicToBuyer => false,
        };
    }

    /**
     * Whether the party being rated may post a public reply.
     *
     * Only on public reviews, and only one — see Rating::reply_body. A right
     * of reply on a rating nobody can read is not a right of reply.
     */
    public function allowsReply(): bool
    {
        return $this->isPublic();
    }

    /**
     * The event this direction hangs off.
     */
    public function source(): RatingSource
    {
        return match ($this) {
            self::BuyerToSeller, self::SellerToBuyer => RatingSource::Order,
            self::BuyerToMechanic, self::SellerToMechanic, self::MechanicToBuyer => RatingSource::Endorsement,
        };
    }

    /**
     * The directions a rating list may show to the public.
     *
     * @return array<int, string>
     */
    public static function publicValues(): array
    {
        return array_values(array_map(
            static fn (self $direction): string => $direction->value,
            array_filter(self::cases(), static fn (self $direction): bool => $direction->isPublic()),
        ));
    }

    /**
     * The directions that are never shown to the public.
     *
     * @return array<int, string>
     */
    public static function privateValues(): array
    {
        return array_values(array_map(
            static fn (self $direction): string => $direction->value,
            array_filter(self::cases(), static fn (self $direction): bool => ! $direction->isPublic()),
        ));
    }

    /**
     * The two directions a completed order entitles: the buyer's review of
     * the shop, and the shop's rating of the buyer.
     *
     * @return array<int, self>
     */
    public static function forOrders(): array
    {
        return [self::BuyerToSeller, self::SellerToBuyer];
    }

    /**
     * @return array<int, array{value: string, label: string, public: bool}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $direction): array => [
            'value' => $direction->value,
            'label' => $direction->label(),
            'public' => $direction->isPublic(),
        ], self::cases());
    }
}
