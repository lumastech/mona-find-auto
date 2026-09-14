<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\MakeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A vehicle manufacturer.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $country
 * @property bool $is_popular
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, VehicleModel> $vehicleModels
 */
class Make extends Model
{
    /** @use HasFactory<MakeFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public static function booted(): void
    {
        static::saving(function (self $make): void {
            if (blank($make->slug)) {
                $make->slug = Str::slug($make->name);
            }
        });
    }

    /**
     * @return HasMany<VehicleModel, $this>
     */
    public function vehicleModels(): HasMany
    {
        return $this->hasMany(VehicleModel::class)->orderByDesc('is_popular')->orderBy('name');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Makes a seller may pick, most-driven first.
     *
     * Nearly every vehicle on a Zambian road is one of a dozen marques, so
     * putting those at the top of the list saves a scroll on a slow phone.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSelectable(Builder $query): void
    {
        $query->where('is_active', true)
            ->orderByDesc('is_popular')
            ->orderBy('position')
            ->orderBy('name');
    }
}
