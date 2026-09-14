<?php

declare(strict_types=1);

namespace App\Integrations\Maps\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A WGS84 point.
 *
 * @implements Arrayable<string, float>
 */
final readonly class Coordinates implements Arrayable
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    /**
     * Great-circle distance in metres. Used for "nearest first" sorting and
     * radius filters — never for anything a buyer is charged for.
     */
    public function distanceInMetresTo(self $other): int
    {
        $earthRadiusMetres = 6_371_000;

        $latitudeDelta = deg2rad($other->latitude - $this->latitude);
        $longitudeDelta = deg2rad($other->longitude - $this->longitude);

        $haversine = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($other->latitude)) * sin($longitudeDelta / 2) ** 2;

        return (int) round($earthRadiusMetres * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine)));
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
