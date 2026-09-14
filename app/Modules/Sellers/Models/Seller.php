<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Models;

use App\Integrations\Maps\Data\Coordinates;
use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use App\Modules\Identity\Support\PhoneNumberCast;
use App\Modules\Sellers\Database\Factories\SellerFactory;
use App\Modules\Sellers\Enums\DocumentType;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A selling business: shop, garage, breaker, dealer.
 *
 * @property int $id
 * @property int $user_id
 * @property SellerType $type
 * @property string $business_name
 * @property string $slug
 * @property string|null $registration_number
 * @property string|null $description
 * @property int $province_id
 * @property int $city_id
 * @property string $street
 * @property string|null $plot_number
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $place_id
 * @property string|null $formatted_address
 * @property string $phone
 * @property string $email
 * @property string $contact_person
 * @property int|null $bay_count
 * @property array<string, array{open: string, close: string}>|null $opening_hours
 * @property VerificationStatus $verification_status
 * @property string|null $verification_note
 * @property string|null $rejection_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $verified_at
 * @property int|null $verified_by
 * @property Carbon|null $inspection_scheduled_for
 * @property bool $offers_pickup
 * @property bool $offers_delivery
 * @property Money $delivery_fee_ngwee
 * @property string|null $delivery_note
 * @property PaymentMode $payment_mode
 * @property int|null $monetisation_policy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Province $province
 * @property-read City $city
 * @property-read Collection<int, SellerPolicy> $policies
 * @property-read Collection<int, PayoutAccount> $payoutAccounts
 * @property-read Collection<int, SellerVerificationEvent> $verificationEvents
 */
class Seller extends Model implements HasMedia
{
    /** @use HasFactory<SellerFactory> */
    use HasFactory, InteractsWithMedia;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SellerType::class,
            'verification_status' => VerificationStatus::class,
            'payment_mode' => PaymentMode::class,
            'phone' => PhoneNumberCast::class,
            'opening_hours' => 'array',
            /* Fulfilment columns are the Orders module's; only it writes them. */
            'offers_pickup' => 'boolean',
            'offers_delivery' => 'boolean',
            'delivery_fee_ngwee' => MoneyCast::class,
            'bay_count' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'inspection_scheduled_for' => 'datetime',
        ];
    }

    /**
     * Give every business a stable public slug on creation.
     *
     * The slug is what a seller's page is linked by, so it is never rebuilt
     * from a renamed business — an old link has to keep working.
     */
    public static function booted(): void
    {
        static::creating(function (self $seller): void {
            if (blank($seller->slug)) {
                $seller->slug = self::uniqueSlugFor($seller->business_name);
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
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
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
     * Every version of every policy, newest first.
     *
     * @return HasMany<SellerPolicy, $this>
     */
    public function policies(): HasMany
    {
        return $this->hasMany(SellerPolicy::class)->orderByDesc('version');
    }

    /**
     * Only the version of each policy in force now — what a buyer accepts.
     *
     * @return HasMany<SellerPolicy, $this>
     */
    public function currentPolicies(): HasMany
    {
        return $this->policies()->where('is_current', true);
    }

    /**
     * @return HasMany<PayoutAccount, $this>
     */
    public function payoutAccounts(): HasMany
    {
        return $this->hasMany(PayoutAccount::class)->orderByDesc('is_default')->orderBy('id');
    }

    /**
     * @return HasOne<PayoutAccount, $this>
     */
    public function defaultPayoutAccount(): HasOne
    {
        return $this->hasOne(PayoutAccount::class)->where('is_default', true);
    }

    /**
     * @return HasMany<SellerVerificationEvent, $this>
     */
    public function verificationEvents(): HasMany
    {
        return $this->hasMany(SellerVerificationEvent::class)->orderByDesc('id');
    }

    /**
     * Documents live on the private disk and are never linked to directly;
     * staff read them through a policy-checked streaming route.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents')
            ->useDisk('local')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('logo')
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Whether this shop will send a part out at all.
     *
     * Read at checkout to decide which fulfilment options the buyer is
     * offered. A seller who offers neither cannot be ordered from, which is
     * why offers_pickup defaults to true: collecting from the counter is what
     * every shop on the platform already does.
     */
    public function offersDelivery(): bool
    {
        return $this->offers_delivery;
    }

    public function offersPickup(): bool
    {
        return $this->offers_pickup;
    }

    public function isVerified(): bool
    {
        return $this->verification_status->isVerified();
    }

    /**
     * Whether the badge may be granted.
     *
     * A registration number is optional while somebody is signing up and
     * mandatory before they are badged: the badge tells a buyer MonaFind
     * checked a real, registered business, and there is nothing to check
     * without it.
     */
    public function mayBeVerified(): bool
    {
        return filled($this->registration_number);
    }

    /**
     * The policy of a given type that is in force now.
     */
    public function currentPolicy(PolicyType $type): ?SellerPolicy
    {
        return $this->policies()
            ->where('type', $type)
            ->where('is_current', true)
            ->first();
    }

    /**
     * Which required policies are still missing, for the review step and the
     * submission guard.
     *
     * @return array<int, PolicyType>
     */
    public function missingPolicies(): array
    {
        $published = $this->currentPolicies()->pluck('type')->all();

        return array_values(array_filter(
            PolicyType::required(),
            static fn (PolicyType $type): bool => ! in_array($type, $published, true),
        ));
    }

    /**
     * Which required documents are still missing.
     *
     * @return array<int, DocumentType>
     */
    public function missingDocuments(): array
    {
        $uploaded = $this->getMedia('documents')
            ->map(static fn (Media $media): mixed => $media->getCustomProperty('document_type'))
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->all();

        return array_values(array_filter(
            $this->type->requiredDocuments(),
            static fn (DocumentType $type): bool => ! in_array($type->value, $uploaded, true),
        ));
    }

    /**
     * The map pin, when the seller dropped one.
     */
    public function coordinates(): ?Coordinates
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return new Coordinates($this->latitude, $this->longitude);
    }

    /**
     * The trading address on one line.
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

    /**
     * Sellers a buyer may see at all. A draft or rejected application is not
     * a shop, and a suspended one has been taken down.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->whereIn('verification_status', VerificationStatus::publiclyVisibleValues());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->where('verification_status', VerificationStatus::Verified);
    }

    /**
     * Applications still waiting on staff, oldest first — a queue, not a list.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInVerificationQueue(Builder $query): void
    {
        $query->whereIn('verification_status', VerificationStatus::queueValues())->oldest('submitted_at');
    }

    /**
     * Free-text search over what staff actually type into the box.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('business_name', 'like', "%{$term}%")
                ->orWhere('registration_number', 'like', "%{$term}%")
                ->orWhere('contact_person', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    /**
     * A slug nobody else holds. Two "Kabwata Motors" get "kabwata-motors"
     * and "kabwata-motors-2" rather than a collision at insert time.
     */
    private static function uniqueSlugFor(string $businessName): string
    {
        $base = Str::slug($businessName) ?: 'seller';
        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }
}
