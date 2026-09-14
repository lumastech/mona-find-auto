<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Models;

use App\Integrations\Maps\Data\Coordinates;
use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use App\Modules\Identity\Support\PhoneNumberCast;
use App\Modules\Mechanics\Database\Factories\MechanicProfileFactory;
use App\Modules\Mechanics\Enums\MechanicStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A mechanic's public profile.
 *
 * Two badges, and they are independent. "MonaFind approved" is `status` —
 * staff checked the qualification and the work history. "Endorsed by X" is an
 * endorsement row per shop, and a mechanic may hold any number of them. A
 * mechanic can be approved with no endorsements, and cannot show an
 * endorsement without being approved, because an unapproved profile is not
 * visible at all.
 *
 * That last rule is the one everything else depends on, and it is expressed
 * as scopePubliclyVisible(). Every public read — the directory, the profile
 * page, the API, the rating feed's subject — goes through it, because the
 * failure that matters is not an unapproved profile rendering badly but an
 * unapproved profile being reachable at all.
 *
 * @property int $id
 * @property int $user_id
 * @property string $display_name
 * @property string $slug
 * @property string|null $headline
 * @property string|null $bio
 * @property string $qualification
 * @property string|null $qualification_institution
 * @property int|null $qualification_year
 * @property int $years_experience
 * @property int $province_id
 * @property int $city_id
 * @property string|null $street
 * @property string|null $plot_number
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string $phone
 * @property string|null $email
 * @property bool $is_mobile
 * @property bool $accepting_work
 * @property MechanicStatus $status
 * @property string|null $review_note
 * @property string|null $rejection_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $approver
 * @property-read Province $province
 * @property-read City $city
 * @property-read Collection<int, MechanicSpeciality> $specialities
 * @property-read Collection<int, MechanicWorkHistory> $workHistory
 * @property-read Collection<int, MechanicReference> $references
 * @property-read Collection<int, MechanicEndorsement> $endorsements
 * @property-read Collection<int, MechanicEndorsement> $activeEndorsements
 */
class MechanicProfile extends Model implements HasMedia
{
    /** @use HasFactory<MechanicProfileFactory> */
    use HasFactory, InteractsWithMedia;

    /** The mechanic's photograph. Public: it is on the directory card. */
    public const AVATAR_COLLECTION = 'avatar';

    /**
     * Certificates and trade papers.
     *
     * Private disk, like a seller's documents: these are scans of somebody's
     * qualifications and the reviewer is the only person with a reason to
     * open them.
     */
    public const CERTIFICATES_COLLECTION = 'certificates';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MechanicStatus::class,
            'phone' => PhoneNumberCast::class,
            'is_mobile' => 'boolean',
            'accepting_work' => 'boolean',
            'years_experience' => 'integer',
            'qualification_year' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * A stable public slug, set once. A mechanic who changes the name they
     * trade under keeps the link people already have.
     */
    public static function booted(): void
    {
        static::creating(function (self $profile): void {
            if (blank($profile->slug)) {
                $profile->slug = self::uniqueSlugFor($profile->display_name);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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
     * @return BelongsToMany<MechanicSpeciality, $this>
     */
    public function specialities(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpeciality::class, 'mechanic_profile_speciality')
            ->orderBy('position')
            ->orderBy('name');
    }

    /**
     * @return HasMany<MechanicWorkHistory, $this>
     */
    public function workHistory(): HasMany
    {
        return $this->hasMany(MechanicWorkHistory::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Never loaded by a public surface — see MechanicReference.
     *
     * @return HasMany<MechanicReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(MechanicReference::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<MechanicEndorsement, $this>
     */
    public function endorsements(): HasMany
    {
        return $this->hasMany(MechanicEndorsement::class)->latest('id');
    }

    /**
     * The ones that are badges. What the profile page lists.
     *
     * @return HasMany<MechanicEndorsement, $this>
     */
    public function activeEndorsements(): HasMany
    {
        return $this->endorsements()->active();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::AVATAR_COLLECTION)
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection(self::CERTIFICATES_COLLECTION)
            ->useDisk('local')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections(self::AVATAR_COLLECTION)
            ->nonQueued()
            ->format('webp')
            ->fit(Fit::Crop, 256, 256);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isApproved(): bool
    {
        return $this->status->isApproved();
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status->isPubliclyVisible();
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function belongsToUser(User $user): bool
    {
        return $this->user_id === $user->getKey();
    }

    /**
     * Whether there is enough here for a reviewer to decide.
     *
     * A qualification and at least one speciality: without the first there is
     * nothing to check, and without the second the profile cannot be found by
     * anybody looking for the work it does.
     */
    public function isReadyToSubmit(): bool
    {
        return filled($this->qualification)
            && filled($this->phone)
            && $this->specialities()->exists();
    }

    /**
     * The map pin, when one was dropped.
     */
    public function coordinates(): ?Coordinates
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return new Coordinates($this->latitude, $this->longitude);
    }

    /**
     * Where they work, on one line. The street is deliberately left out: a
     * mechanic's address is a home address more often than not, and the
     * directory's job is to say which town somebody is in.
     */
    public function locality(): string
    {
        return collect([$this->city->name, $this->province->name])->filter()->implode(', ');
    }

    /**
     * Profiles anybody may see. Approved and nothing else.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->whereIn('status', MechanicStatus::publiclyVisibleValues());
    }

    /**
     * Applications still waiting on staff, oldest first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInApprovalQueue(Builder $query): void
    {
        $query->whereIn('status', MechanicStatus::queueValues())->oldest('submitted_at');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('display_name', 'like', "%{$term}%")
                ->orWhere('headline', 'like', "%{$term}%")
                ->orWhere('qualification', 'like', "%{$term}%");
        });
    }

    /**
     * A slug nobody else holds.
     */
    private static function uniqueSlugFor(string $displayName): string
    {
        $base = Str::slug($displayName) ?: 'mechanic';
        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }
}
