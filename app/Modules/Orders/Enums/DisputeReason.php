<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * Why a buyer has stopped an order.
 *
 * A fixed list rather than free text, and a short one. It exists so that a
 * moderator opening the queue can sort by what actually went wrong, and so
 * that a seller's dispute rate — which decides whether they keep direct
 * payment — can be read against reasons rather than a count. The buyer writes
 * the details in their own words underneath; the reason is only the heading.
 *
 * WrongPart and NotAsDescribed both sit within the platform's minimum refund
 * rule, so a seller policy that excludes them does not apply.
 */
enum DisputeReason: string
{
    case NotReceived = 'not_received';
    case WrongPart = 'wrong_part';
    case Damaged = 'damaged';
    case NotAsDescribed = 'not_as_described';
    case Counterfeit = 'counterfeit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotReceived => 'I never got it',
            self::WrongPart => 'Wrong part',
            self::Damaged => 'Arrived damaged',
            self::NotAsDescribed => 'Not as described',
            self::Counterfeit => 'Counterfeit or fake',
            self::Other => 'Something else',
        };
    }

    public function guidance(): string
    {
        return match ($this) {
            self::NotReceived => 'The order was marked collected or delivered but nothing reached you.',
            self::WrongPart => 'The part does not fit your vehicle or is not what you ordered.',
            self::Damaged => 'The part was broken, cracked or leaking when you got it.',
            self::NotAsDescribed => 'The condition, brand or specification differs from the listing.',
            self::Counterfeit => 'The part is passed off as a brand it is not.',
            self::Other => 'Tell us what happened in your own words.',
        };
    }

    /**
     * Whether the platform's minimum refund rule covers this reason whatever
     * the seller's own policy says.
     */
    public function coveredByPlatformMinimum(): bool
    {
        return in_array($this, [self::WrongPart, self::Damaged, self::NotAsDescribed, self::Counterfeit], true);
    }

    /**
     * @return array<int, array{value: string, label: string, guidance: string, covered: bool}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $reason): array => [
            'value' => $reason->value,
            'label' => $reason->label(),
            'guidance' => $reason->guidance(),
            'covered' => $reason->coveredByPlatformMinimum(),
        ], self::cases());
    }
}
