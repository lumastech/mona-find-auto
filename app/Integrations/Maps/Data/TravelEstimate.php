<?php

declare(strict_types=1);

namespace App\Integrations\Maps\Data;

/**
 * Road distance and duration between two points.
 */
final readonly class TravelEstimate
{
    public function __construct(
        public int $distanceInMetres,
        public int $durationInSeconds,
    ) {}

    public function distanceInKilometres(): float
    {
        return round($this->distanceInMetres / 1000, 1);
    }
}
