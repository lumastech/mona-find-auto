<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

use App\Modules\Catalog\Enums\Concerns\SpecificationEnum;

/**
 * The shape of the vehicle a part fits.
 *
 * Body panels, glass and lights are body-specific even when the model and
 * year are identical, which is why this sits beside the model rather than
 * inside it.
 */
enum BodyType: string
{
    use SpecificationEnum;

    case Hatchback = 'hatchback';
    case Sedan = 'sedan';
    case StationWagon = 'station_wagon';
    case Suv = 'suv';
    case Pickup = 'pickup';
    case Van = 'van';
    case Minibus = 'minibus';
    case Truck = 'truck';
    case Bus = 'bus';
    case Coupe = 'coupe';

    public function label(): string
    {
        return match ($this) {
            self::Hatchback => 'Hatchback',
            self::Sedan => 'Sedan',
            self::StationWagon => 'Station wagon',
            self::Suv => 'SUV',
            self::Pickup => 'Pickup',
            self::Van => 'Van',
            self::Minibus => 'Minibus',
            self::Truck => 'Truck',
            self::Bus => 'Bus',
            self::Coupe => 'Coupe',
        };
    }
}
