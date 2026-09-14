<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Integrations\Maps\Data\Coordinates;
use App\Modules\Identity\Models\UserAddress;

/**
 * Where a delivery is going, as it stood when the order was placed.
 *
 * Copied off the buyer's address book rather than joined to it. A buyer who
 * corrects a typo in "Plot 42" a fortnight later must not thereby change the
 * address a parcel was already sent to, and one who deletes the entry
 * entirely must not leave a courier manifest with a blank on it. The
 * address_id is kept alongside only so the two can be compared; nothing reads
 * the live row.
 */
final readonly class DeliveryAddress
{
    public function __construct(
        public string $recipientName,
        public string $recipientPhone,
        public string $street,
        public ?string $plotNumber,
        public string $city,
        public string $province,
        public ?string $directions = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $formattedAddress = null,
    ) {}

    public static function fromUserAddress(UserAddress $address): self
    {
        $address->loadMissing(['city', 'province']);

        return new self(
            recipientName: $address->recipient_name,
            recipientPhone: (string) $address->recipient_phone,
            street: $address->street,
            plotNumber: $address->plot_number,
            city: $address->city->name,
            province: $address->province->name,
            directions: $address->directions,
            latitude: $address->latitude,
            longitude: $address->longitude,
            formattedAddress: $address->formatted_address,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        return new self(
            recipientName: (string) ($snapshot['recipient_name'] ?? ''),
            recipientPhone: (string) ($snapshot['recipient_phone'] ?? ''),
            street: (string) ($snapshot['street'] ?? ''),
            plotNumber: isset($snapshot['plot_number']) ? (string) $snapshot['plot_number'] : null,
            city: (string) ($snapshot['city'] ?? ''),
            province: (string) ($snapshot['province'] ?? ''),
            directions: isset($snapshot['directions']) ? (string) $snapshot['directions'] : null,
            latitude: isset($snapshot['latitude']) ? (float) $snapshot['latitude'] : null,
            longitude: isset($snapshot['longitude']) ? (float) $snapshot['longitude'] : null,
            formattedAddress: isset($snapshot['formatted_address']) ? (string) $snapshot['formatted_address'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'street' => $this->street,
            'plot_number' => $this->plotNumber,
            'city' => $this->city,
            'province' => $this->province,
            'directions' => $this->directions,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'formatted_address' => $this->formattedAddress,
        ];
    }

    /**
     * The address on one line, for a packing slip or a courier manifest.
     */
    public function singleLine(): string
    {
        return collect([$this->plotNumber, $this->street, $this->city, $this->province])
            ->filter()
            ->implode(', ');
    }

    public function coordinates(): ?Coordinates
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return new Coordinates($this->latitude, $this->longitude);
    }
}
