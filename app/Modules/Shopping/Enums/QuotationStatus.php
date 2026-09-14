<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Enums;

/**
 * Where a request for quotation stands.
 *
 * A quotation is a negotiation with exactly two moves in it: the buyer asks,
 * the seller answers, and then the buyer either takes the price or lets it
 * lapse. Everything else — a seller declining, a quote going stale — is an
 * ending rather than a state anybody acts from, which is why the terminal
 * cases carry no outward transitions at all.
 *
 * Expiry is the reason this is a state machine and not a pair of booleans. A
 * quoted price has a validity date on it, and once that date passes the price
 * is no longer the seller's offer — so a stale quote has to stop being
 * acceptable on its own, without anybody touching it.
 */
enum QuotationStatus: string
{
    /** The buyer has asked; the seller has not answered. */
    case Open = 'open';

    /** The seller has named a price and a date it is good until. */
    case Quoted = 'quoted';

    /** The buyer took the price. The quoted price now governs the cart line. */
    case Accepted = 'accepted';

    /** The seller will not quote for this. */
    case Declined = 'declined';

    /** Nobody acted before the validity date passed. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Awaiting quote',
            self::Quoted => 'Quoted',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Open => 'The seller has not answered this request yet.',
            self::Quoted => 'The seller has quoted a price. Accept it to add it to your cart.',
            self::Accepted => 'You accepted this quote and it is in your cart at the quoted price.',
            self::Declined => 'The seller will not quote for this request.',
            self::Expired => 'This quote passed its validity date and can no longer be accepted.',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'outline',
            self::Quoted => 'default',
            self::Accepted => 'secondary',
            self::Declined, self::Expired => 'destructive',
        };
    }

    /**
     * Whether this request is still going anywhere.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::Quoted], true);
    }

    /**
     * The moves that are legal from here.
     *
     * Declared once, on the state, so that the service, the policy and the
     * tests cannot each hold a different opinion about whether an expired
     * quote can be accepted.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Quoted, self::Declined, self::Expired],
            self::Quoted => [self::Accepted, self::Declined, self::Expired],
            self::Accepted, self::Declined, self::Expired => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * The statuses a seller's inbox opens on: the ones that need them.
     *
     * @return array<int, string>
     */
    public static function needingSellerValues(): array
    {
        return [self::Open->value];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
