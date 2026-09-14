<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

use App\Modules\Catalog\Enums\Concerns\SpecificationEnum;

/**
 * Which wheels the engine drives.
 *
 * Driveshafts, hubs and differentials all differ across these, and a buyer
 * on a Zambian road is usually shopping for a 4x4 or explicitly not.
 */
enum DriveType: string
{
    use SpecificationEnum;

    case FrontWheel = 'fwd';
    case RearWheel = 'rwd';
    case AllWheel = 'awd';
    case FourWheel = '4wd';

    public function label(): string
    {
        return match ($this) {
            self::FrontWheel => 'Front-wheel drive',
            self::RearWheel => 'Rear-wheel drive',
            self::AllWheel => 'All-wheel drive',
            self::FourWheel => '4x4',
        };
    }
}
