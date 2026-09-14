<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Models;

use App\Modules\Mechanics\Database\Factories\MechanicSpecialityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One entry in the controlled list of things a mechanic does.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MechanicProfile> $profiles
 */
class MechanicSpeciality extends Model
{
    /** @use HasFactory<MechanicSpecialityFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<MechanicProfile, $this>
     */
    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(MechanicProfile::class, 'mechanic_profile_speciality');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The ones an applicant may still choose and the directory still filters
     * on. A retired speciality stays on the profiles that already claim it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    /**
     * @return array{id: int, name: string, slug: string, description: string|null}
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
        ];
    }
}
