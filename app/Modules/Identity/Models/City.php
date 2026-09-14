<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Integrations\Maps\Data\Coordinates;
use App\Modules\Identity\Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A town or city within a province.
 *
 * @property int $id
 * @property int $province_id
 * @property string $name
 * @property string $slug
 * @property float|null $latitude
 * @property float|null $longitude
 * @property bool $is_major
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Province $province
 */
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_major' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * The town centre, when the reference list carries one.
     */
    public function coordinates(): ?Coordinates
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return new Coordinates($this->latitude, $this->longitude);
    }
}
