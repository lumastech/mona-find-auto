<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

/**
 * Which of the three areas a banner appears in.
 *
 * "Payouts run late this week" is for sellers and would only alarm buyers;
 * "delivery to Solwezi is suspended" is the other way round.
 */
enum AnnouncementAudience: string
{
    case Everyone = 'everyone';
    case Buyers = 'buyers';
    case Sellers = 'sellers';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Everyone',
            self::Buyers => 'Storefront (buyers and guests)',
            self::Sellers => 'Seller portal',
            self::Staff => 'Staff console',
        };
    }

    /**
     * Whether a banner for this audience shows in the given Inertia area.
     */
    public function reaches(string $area): bool
    {
        return match ($this) {
            self::Everyone => true,
            self::Buyers => $area === 'storefront',
            self::Sellers => $area === 'seller',
            self::Staff => $area === 'admin',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $audience): array => [
            'value' => $audience->value,
            'label' => $audience->label(),
        ], self::cases());
    }
}
