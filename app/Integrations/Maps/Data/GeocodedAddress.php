<?php

declare(strict_types=1);

namespace App\Integrations\Maps\Data;

/**
 * A resolved address with the point it sits on.
 */
final readonly class GeocodedAddress
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $formattedAddress,
        public Coordinates $coordinates,
        public ?string $placeId = null,
        public ?string $locality = null,
        public ?string $province = null,
        public string $country = 'ZM',
        public array $raw = [],
    ) {}
}
