<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\VehicleModelFactory;
use App\Modules\Catalog\Enums\BodyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A model within a make: Toyota Hilux, Nissan Hardbody.
 *
 * @property int $id
 * @property int $make_id
 * @property string $name
 * @property string $slug
 * @property BodyType|null $body_type
 * @property int|null $production_start_year
 * @property int|null $production_end_year
 * @property bool $is_popular
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Make $make
 */
class VehicleModel extends Model
{
    /** @use HasFactory<VehicleModelFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body_type' => BodyType::class,
            'production_start_year' => 'integer',
            'production_end_year' => 'integer',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function booted(): void
    {
        static::saving(function (self $model): void {
            if (blank($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    /**
     * @return BelongsTo<Make, $this>
     */
    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class);
    }

    /**
     * "Toyota Hilux" — what a buyer recognises, and what search indexes.
     */
    public function fullName(): string
    {
        return trim($this->make->name.' '.$this->name);
    }

    /**
     * "1997–2005", or "2015 onwards" for a model still being built.
     */
    public function productionYears(): ?string
    {
        if ($this->production_start_year === null) {
            return null;
        }

        return $this->production_end_year === null
            ? $this->production_start_year.' onwards'
            : $this->production_start_year.'–'.$this->production_end_year;
    }

    /**
     * Whether a fitment year is plausible for this model.
     *
     * Advisory rather than enforced: reference data on production runs is
     * never complete, and refusing a real listing over a missing end year
     * would cost a seller a sale.
     */
    public function coversYear(int $year): bool
    {
        if ($this->production_start_year !== null && $year < $this->production_start_year) {
            return false;
        }

        return $this->production_end_year === null || $year <= $this->production_end_year;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeSelectable(Builder $query): void
    {
        $query->where('is_active', true)->orderByDesc('is_popular')->orderBy('name');
    }
}
