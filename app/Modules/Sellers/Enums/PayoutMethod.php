<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * Where a payout lands: a bank account or a mobile-money wallet.
 */
enum PayoutMethod: string
{
    case Bank = 'bank';
    case MobileMoney = 'mobile_money';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank account',
            self::MobileMoney => 'Mobile money',
        };
    }

    public function isBank(): bool
    {
        return $this === self::Bank;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $method): array => ['value' => $method->value, 'label' => $method->label()],
            self::cases(),
        );
    }
}
