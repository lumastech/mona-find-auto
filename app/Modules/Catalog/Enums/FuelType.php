<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

use App\Modules\Catalog\Enums\Concerns\SpecificationEnum;

/**
 * What the vehicle a part fits burns.
 *
 * Diesel and petrol versions of the same model take different injectors,
 * pumps and filters, so this is a fitment fact rather than a nicety.
 */
enum FuelType: string
{
    use SpecificationEnum;

    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case Electric = 'electric';
    case Lpg = 'lpg';

    public function label(): string
    {
        return match ($this) {
            self::Petrol => 'Petrol',
            self::Diesel => 'Diesel',
            self::Hybrid => 'Hybrid',
            self::Electric => 'Electric',
            self::Lpg => 'LPG',
        };
    }
}
