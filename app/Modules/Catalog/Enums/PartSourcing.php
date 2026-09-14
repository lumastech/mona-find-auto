<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

use App\Modules\Catalog\Enums\Concerns\SpecificationEnum;

/**
 * Where the part came from: the vehicle's maker, or somebody else's factory.
 *
 * This is not the same question as the condition badge. A brand-new part can
 * be aftermarket and a used part can be genuine OEM, so buyers filter on both
 * independently.
 */
enum PartSourcing: string
{
    use SpecificationEnum;

    /** Made by, or for, the vehicle manufacturer. */
    case Oem = 'oem';

    /** Made by an independent manufacturer to fit. */
    case Aftermarket = 'aftermarket';

    public function label(): string
    {
        return match ($this) {
            self::Oem => 'OEM / genuine',
            self::Aftermarket => 'Aftermarket',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Oem => 'Made by or for the vehicle manufacturer.',
            self::Aftermarket => 'Made by an independent manufacturer to fit the same vehicle.',
        };
    }
}
