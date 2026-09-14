<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Database\Factories\ProvinceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One of Zambia's ten provinces.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $capital
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Province extends Model
{
    /** @use HasFactory<ProvinceFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class)->orderByDesc('is_major')->orderBy('name');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
