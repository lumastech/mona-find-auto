<?php

declare(strict_types=1);

namespace App\Integrations\Maps;

use App\Contracts\MapsProvider;
use App\Integrations\Maps\Data\Coordinates;
use App\Integrations\Maps\Data\GeocodedAddress;
use App\Integrations\Maps\Data\TravelEstimate;
use App\Integrations\Support\RecordsCalls;

/**
 * Deterministic geocoding for tests and local development.
 *
 * Unknown addresses are hashed into a point a few kilometres around central
 * Lusaka, so results are stable across runs without any network access.
 */
class FakeMapsProvider implements MapsProvider
{
    use RecordsCalls;

    /** Cairo Road, Lusaka. */
    private const ORIGIN_LATITUDE = -15.4167;

    private const ORIGIN_LONGITUDE = 28.2833;

    /** Assumed average speed for the duration estimate, in metres per second (~35 km/h). */
    private const AVERAGE_SPEED = 9.7;

    /** @var array<string, GeocodedAddress> */
    private array $stubs = [];

    public function geocode(string $address): ?GeocodedAddress
    {
        $this->recordCall(__FUNCTION__, ['address' => $address]);

        $key = $this->normalise($address);

        if (array_key_exists($key, $this->stubs)) {
            return $this->stubs[$key];
        }

        if ($key === '') {
            return null;
        }

        return new GeocodedAddress(
            formattedAddress: $address,
            coordinates: $this->scatter($key),
            placeId: 'fake_place_'.substr(md5($key), 0, 12),
            locality: 'Lusaka',
            province: 'Lusaka Province',
        );
    }

    public function reverseGeocode(Coordinates $coordinates): ?GeocodedAddress
    {
        $this->recordCall(__FUNCTION__, $coordinates->toArray());

        return new GeocodedAddress(
            formattedAddress: sprintf('%.4f, %.4f, Lusaka, Zambia', $coordinates->latitude, $coordinates->longitude),
            coordinates: $coordinates,
            locality: 'Lusaka',
            province: 'Lusaka Province',
        );
    }

    public function travelEstimate(Coordinates $origin, Coordinates $destination): ?TravelEstimate
    {
        $this->recordCall(__FUNCTION__, [
            'origin' => $origin->toArray(),
            'destination' => $destination->toArray(),
        ]);

        /** Roads are never straight lines; 1.3x the great-circle distance is close enough for a fake. */
        $metres = (int) round($origin->distanceInMetresTo($destination) * 1.3);

        return new TravelEstimate(
            distanceInMetres: $metres,
            durationInSeconds: (int) round($metres / self::AVERAGE_SPEED),
        );
    }

    /**
     * Pin an address to an exact point for a test.
     */
    public function stubAddress(string $address, Coordinates $coordinates): self
    {
        $this->stubs[$this->normalise($address)] = new GeocodedAddress(
            formattedAddress: $address,
            coordinates: $coordinates,
            placeId: 'fake_place_'.substr(md5($address), 0, 12),
            locality: 'Lusaka',
            province: 'Lusaka Province',
        );

        return $this;
    }

    private function normalise(string $address): string
    {
        return trim(mb_strtolower($address));
    }

    /**
     * Spread an address deterministically over roughly 10km around Lusaka.
     */
    private function scatter(string $key): Coordinates
    {
        $hash = md5($key);

        $latitudeOffset = (hexdec(substr($hash, 0, 4)) % 2000 - 1000) / 20000;
        $longitudeOffset = (hexdec(substr($hash, 4, 4)) % 2000 - 1000) / 20000;

        return new Coordinates(
            latitude: round(self::ORIGIN_LATITUDE + $latitudeOffset, 6),
            longitude: round(self::ORIGIN_LONGITUDE + $longitudeOffset, 6),
        );
    }
}
