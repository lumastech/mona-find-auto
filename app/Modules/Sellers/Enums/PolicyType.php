<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * The four policies every seller publishes.
 *
 * A buyer accepts the current version of each at checkout, and the accepted
 * version is recorded against the order — so these are versioned rather than
 * edited in place.
 */
enum PolicyType: string
{
    case Delivery = 'delivery';
    case Refund = 'refund';
    case Warranty = 'warranty';
    case Terms = 'terms';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery policy',
            self::Refund => 'Refund policy',
            self::Warranty => 'Warranty policy',
            self::Terms => 'Terms of sale',
        };
    }

    public function guidance(): string
    {
        return match ($this) {
            self::Delivery => 'How you get parts to buyers, what it costs, and how long it takes.',
            self::Refund => 'When you take a part back and how a buyer gets their money.',
            self::Warranty => 'What you guarantee, for how long, and what voids it.',
            self::Terms => 'Anything else a buyer agrees to when they order from you.',
        };
    }

    /**
     * Whether the platform's own minimum is shown next to this policy.
     *
     * MonaFind's floor — a wrong or damaged part is refundable within three
     * days whatever the seller writes — has to sit beside the refund policy
     * so a buyer cannot be talked out of it by the seller's wording.
     */
    public function showsPlatformMinimum(): bool
    {
        return $this === self::Refund;
    }

    /**
     * Policies a seller must publish before their application is reviewed.
     *
     * @return array<int, self>
     */
    public static function required(): array
    {
        return [self::Delivery, self::Refund, self::Warranty];
    }

    /**
     * @return array<int, array{value: string, label: string, guidance: string, required: bool, shows_platform_minimum: bool}>
     */
    public static function options(): array
    {
        $required = self::required();

        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'guidance' => $type->guidance(),
            'required' => in_array($type, $required, true),
            'shows_platform_minimum' => $type->showsPlatformMinimum(),
        ], self::cases());
    }
}
