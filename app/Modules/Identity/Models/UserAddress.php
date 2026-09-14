<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Integrations\Maps\Data\Coordinates;
use App\Models\User;
use App\Modules\Identity\Database\Factories\UserAddressFactory;
use App\Modules\Identity\Support\PhoneNumberCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of a buyer's saved delivery addresses.
 *
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string $recipient_name
 * @property string $recipient_phone
 * @property int $province_id
 * @property int $city_id
 * @property string $street
 * @property string|null $plot_number
 * @property string|null $directions
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $place_id
 * @property string|null $formatted_address
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Province $province
 * @property-read City $city
 */
class UserAddress extends Model
{
    /** @use HasFactory<UserAddressFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_phone' => PhoneNumberCast::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /**
     * The map pin, when the buyer dropped one.
     */
    public function coordinates(): ?Coordinates
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return new Coordinates($this->latitude, $this->longitude);
    }

    /**
     * The address on one line, for order confirmations and courier manifests.
     */
    public function singleLine(): string
    {
        return collect([
            $this->plot_number,
            $this->street,
            $this->city->name,
            $this->province->name,
        ])->filter()->implode(', ');
    }
}
