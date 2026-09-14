<?php

declare(strict_types=1);

namespace App\Modules\Search\Enums;

/**
 * How much trouble a seller's orders turn into, in four buckets.
 *
 * The raw dispute rate is never indexed. A percentage on a shop with six
 * orders is noise, it changes on every order, and re-indexing a seller's
 * whole catalogue because a rate moved from 1.9% to 2.0% is work nobody
 * asked for. A band moves rarely and means something to a buyer.
 *
 * The boundaries are read off `risk.dispute_rate_threshold_percent` — the
 * same number that reverts a direct-payment seller to escrow — so the
 * platform only has one opinion about what "too many disputes" means.
 */
enum DisputeBand: string
{
    /** Nothing has gone wrong yet, or there is not enough history to say. */
    case None = 'none';

    /** Disputes happen, well under the platform's threshold. */
    case Low = 'low';

    /** Approaching the threshold that reverts a seller to escrow. */
    case Elevated = 'elevated';

    /** Over the threshold. */
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No disputes',
            self::Low => 'Few disputes',
            self::Elevated => 'Some disputes',
            self::High => 'Frequent disputes',
        };
    }

    /**
     * This band's share of the "low disputes" ranking weight, 0 to 1.
     */
    public function qualityFactor(): float
    {
        return match ($this) {
            self::None => 1.0,
            self::Low => 0.75,
            self::Elevated => 0.35,
            self::High => 0.0,
        };
    }

    /**
     * The band a rate falls in, given the platform's configured threshold.
     *
     * A seller with no completed orders is None rather than High: a new shop
     * has not earned a warning, and starting one at the bottom would make the
     * platform impossible to join.
     */
    public static function forRate(float $ratePercent, float $thresholdPercent): self
    {
        if ($ratePercent <= 0.0) {
            return self::None;
        }

        $threshold = $thresholdPercent > 0.0 ? $thresholdPercent : 2.0;

        return match (true) {
            $ratePercent <= $threshold / 2 => self::Low,
            $ratePercent <= $threshold => self::Elevated,
            default => self::High,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $band): string => $band->value, self::cases());
    }
}
