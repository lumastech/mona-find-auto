<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Integrations\Maps\Data\Coordinates;
use App\Integrations\Maps\Data\GeocodedAddress;
use App\Integrations\Maps\Data\TravelEstimate;

/**
 * Geocoding and distance lookups. Google Maps in production, a deterministic
 * fake elsewhere so tests never touch the network or burn quota.
 */
interface MapsProvider
{
    /**
     * Turn a written address into a point. Null when nothing matches.
     */
    public function geocode(string $address): ?GeocodedAddress;

    /**
     * Turn a point back into a written address. Null when nothing matches.
     */
    public function reverseGeocode(Coordinates $coordinates): ?GeocodedAddress;

    /**
     * Road distance and duration between two points. Null when no route exists.
     */
    public function travelEstimate(Coordinates $origin, Coordinates $destination): ?TravelEstimate;
}
