<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Contracts\MapsProvider;
use App\Integrations\Maps\Data\Coordinates;
use App\Models\User;
use App\Modules\Identity\Models\UserAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A buyer's saved delivery addresses.
 *
 * Two rules are easy to get wrong at a controller and are therefore kept
 * here: exactly one address is the default at any moment, and an address with
 * a map pin carries a formatted address resolved from that pin so couriers
 * are not handed bare coordinates.
 */
class AddressBook
{
    public function __construct(private readonly MapsProvider $maps) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(User $user, array $attributes): UserAddress
    {
        $this->guardAgainstTooManyAddresses($user);

        return DB::transaction(function () use ($user, $attributes): UserAddress {
            /* The first address a buyer saves is their default, whatever they ticked. */
            $isDefault = (bool) ($attributes['is_default'] ?? false) || ! $user->addresses()->exists();

            $address = $user->addresses()->create([
                ...$this->withResolvedPin($attributes),
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                $this->demoteOthers($user, $address);
            }

            return $address;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(UserAddress $address, array $attributes): UserAddress
    {
        return DB::transaction(function () use ($address, $attributes): UserAddress {
            $address->update($this->withResolvedPin($attributes));

            if ($address->is_default) {
                $this->demoteOthers($address->user, $address);
            }

            return $address->refresh();
        });
    }

    /**
     * Remove an address, handing the default on to whatever is left so a
     * buyer never ends up with addresses but no default.
     */
    public function remove(UserAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $wasDefault = $address->is_default;
            $user = $address->user;

            $address->delete();

            if ($wasDefault) {
                $user->addresses()->oldest('id')->first()?->update(['is_default' => true]);
            }
        });
    }

    public function makeDefault(UserAddress $address): UserAddress
    {
        return DB::transaction(function () use ($address): UserAddress {
            $address->update(['is_default' => true]);

            $this->demoteOthers($address->user, $address);

            return $address;
        });
    }

    /**
     * Fill in the readable address behind a dropped pin.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withResolvedPin(array $attributes): array
    {
        $latitude = $attributes['latitude'] ?? null;
        $longitude = $attributes['longitude'] ?? null;

        if ($latitude === null || $longitude === null) {
            return $attributes;
        }

        if (filled($attributes['formatted_address'] ?? null)) {
            return $attributes;
        }

        $geocoded = $this->maps->reverseGeocode(new Coordinates((float) $latitude, (float) $longitude));

        if ($geocoded === null) {
            return $attributes;
        }

        return [
            ...$attributes,
            'formatted_address' => $geocoded->formattedAddress,
            'place_id' => $attributes['place_id'] ?? $geocoded->placeId,
        ];
    }

    private function demoteOthers(User $user, UserAddress $address): void
    {
        $user->addresses()
            ->whereKeyNot($address->getKey())
            ->update(['is_default' => false]);
    }

    private function guardAgainstTooManyAddresses(User $user): void
    {
        $limit = settings()->integer('identity.max_delivery_addresses', 10);

        if ($user->addresses()->count() >= $limit) {
            throw ValidationException::withMessages([
                'label' => "You can save up to {$limit} delivery addresses. Remove one to add another.",
            ]);
        }
    }
}
