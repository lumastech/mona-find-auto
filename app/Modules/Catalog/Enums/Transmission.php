<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

use App\Modules\Catalog\Enums\Concerns\SpecificationEnum;

/**
 * The gearbox the part came out of, or belongs in.
 */
enum Transmission: string
{
    use SpecificationEnum;

    case Manual = 'manual';
    case Automatic = 'automatic';
    case Cvt = 'cvt';
    case SemiAutomatic = 'semi_automatic';
    case DualClutch = 'dual_clutch';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Automatic => 'Automatic',
            self::Cvt => 'CVT',
            self::SemiAutomatic => 'Semi-automatic',
            self::DualClutch => 'Dual-clutch',
        };
    }
}
