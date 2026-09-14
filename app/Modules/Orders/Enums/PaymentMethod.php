<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * What the buyer intends to pay with.
 *
 * Chosen once for the whole order group, because one payment covers every
 * shop in the cart. The value is the buyer's intent, not a record of what
 * happened — the gateway decides that, and the Payments module records it.
 * It is captured here so the checkout widget can be opened on the right
 * channel instead of asking the same question twice.
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case MobileMoney = 'mobile_money';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
            self::MobileMoney => 'Mobile money',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Card => 'Visa or Mastercard. Your card details go straight to our payment provider.',
            self::MobileMoney => 'Airtel Money, MTN MoMo or Zamtel Kwacha.',
        };
    }

    /**
     * The channel name the gateway expects for this method.
     *
     * Kept as a method rather than reusing the case value so that a change in
     * Lenco's vocabulary does not become a change to what is stored on
     * thousands of orders.
     */
    public function gatewayChannel(): string
    {
        return match ($this) {
            self::Card => 'card',
            self::MobileMoney => 'mobile-money',
        };
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $method): array => [
            'value' => $method->value,
            'label' => $method->label(),
            'description' => $method->description(),
        ], self::cases());
    }
}
