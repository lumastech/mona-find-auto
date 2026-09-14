<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Modules\Catalog\Database\Factories\ProductFactory;
use App\Modules\Catalog\Enums\BodyType;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\DriveType;
use App\Modules\Catalog\Enums\FuelType;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Catalog\Enums\Transmission;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Search\Concerns\SearchableListing;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Image\Enums\AlignPosition;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A listing: one part, offered by one seller.
 *
 * Two badges hang off this row and are shown together everywhere a listing
 * appears. `condition` says what the part is and is forced to Car Breaker for
 * breaker sellers; `inspection_status` says whether MonaFind looked at it,
 * starts Uninspected, and only staff may move it. They are independent, and
 * the storefront never renders one without the other.
 *
 * Nothing here changes `status` — App\Modules\Catalog\Services\ListingModerationService
 * owns the lifecycle, so every move is checked, recorded and announced.
 *
 * The freshness columns are Inventory's: only its FreshnessService writes
 * them. They are read here, and only here, because the storefront has to be
 * able to drop a hidden listing in the same query that finds it — see
 * scopePublished(). The FreshnessState enum is a value type, so reading it
 * costs Catalog nothing beyond the name.
 *
 * SearchableListing is Search's, and is the whole of Catalog's dependency on
 * it. Scout indexes Eloquent models, so a searchable listing has to say so on
 * the model; what is indexed, under what name and when, is decided inside the
 * Search module.
 *
 * @property int $id
 * @property int $seller_id
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property int|null $make_id
 * @property int|null $vehicle_model_id
 * @property int|null $year_from
 * @property int|null $year_to
 * @property Condition $condition
 * @property InspectionStatus $inspection_status
 * @property Carbon|null $inspected_at
 * @property int|null $inspected_by
 * @property PartSourcing $sourcing
 * @property string|null $part_number
 * @property string|null $oem_number
 * @property int|null $engine_size_cc
 * @property string|null $engine_code
 * @property FuelType|null $fuel_type
 * @property Transmission|null $transmission
 * @property DriveType|null $drive_type
 * @property BodyType|null $body_type
 * @property string|null $trim
 * @property string|null $chassis_compatibility
 * @property string|null $warranty_text
 * @property bool $delivery_available
 * @property Carbon|null $freshness_confirmed_at
 * @property FreshnessState $freshness_state
 * @property Carbon|null $freshness_hidden_at
 * @property int $stock_reminder_stage
 * @property Carbon|null $stock_reminder_sent_at
 * @property ListingStatus $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $published_at
 * @property Carbon|null $unpublished_at
 * @property Carbon|null $archived_at
 * @property string|null $rejection_reason
 * @property array<string, string>|null $rejection_fields
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Seller $seller
 * @property-read Category $category
 * @property-read Make|null $make
 * @property-read VehicleModel|null $vehicleModel
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read ProductVariant|null $defaultVariant
 * @property-read Collection<int, ListingReviewEvent> $reviewEvents
 */
class Product extends Model implements HasMedia
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, InteractsWithMedia, SearchableListing;

    /** A listing without a photo is not a listing; ten is as many as anyone reads. */
    public const MIN_PHOTOS = 1;

    public const MAX_PHOTOS = 10;

    /** Long enough to walk round a part, short enough to load on a Zambian connection. */
    public const MAX_VIDEO_SECONDS = 60;

    public const PHOTOS_COLLECTION = 'photos';

    public const VIDEO_COLLECTION = 'video';

    /**
     * The conversions the storefront renders, and the only ones the watermark
     * is burnt into. Listed here so the media pipeline and the resources that
     * read it cannot disagree about the names.
     *
     * @var array<int, string>
     */
    public const DISPLAY_CONVERSIONS = ['thumb', 'card', 'web'];

    protected $guarded = [];

    /**
     * A new listing starts fresh.
     *
     * This mirrors the column default so an in-memory model that has not been
     * read back from the database still has a state rather than a null —
     * putting a part up for sale is itself a claim to have it, and code that
     * asks a just-created listing whether buyers may see it deserves an
     * answer.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'freshness_state' => FreshnessState::Fresh->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => Condition::class,
            'inspection_status' => InspectionStatus::class,
            'sourcing' => PartSourcing::class,
            'fuel_type' => FuelType::class,
            'transmission' => Transmission::class,
            'drive_type' => DriveType::class,
            'body_type' => BodyType::class,
            'status' => ListingStatus::class,
            'delivery_available' => 'boolean',
            'freshness_state' => FreshnessState::class,
            'freshness_confirmed_at' => 'datetime',
            'freshness_hidden_at' => 'datetime',
            'stock_reminder_stage' => 'integer',
            'stock_reminder_sent_at' => 'datetime',
            'rejection_fields' => 'array',
            'year_from' => 'integer',
            'year_to' => 'integer',
            'engine_size_cc' => 'integer',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'archived_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'inspected_at' => 'datetime',
        ];
    }

    /**
     * Give every listing a stable public slug on creation.
     *
     * Like a seller's slug, it is never rebuilt from a renamed listing: a
     * link a buyer bookmarked or a search engine indexed has to keep working.
     *
     * ProductService sets the slug itself rather than leaning on this, because
     * a seeder running WithoutModelEvents would otherwise insert a listing
     * with no slug at all. This stays as the backstop for factories and for
     * anything else that creates a listing directly.
     */
    public static function booted(): void
    {
        static::creating(function (self $product): void {
            if (blank($product->slug)) {
                $product->slug = self::uniqueSlugFor($product->name);
            }
        });
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Make, $this>
     */
    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class);
    }

    /**
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The option shown first, and the only one on a single-variant listing.
     *
     * @return HasOne<ProductVariant, $this>
     */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /**
     * @return HasMany<ListingReviewEvent, $this>
     */
    public function reviewEvents(): HasMany
    {
        return $this->hasMany(ListingReviewEvent::class)->orderByDesc('id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Originals stay on the private disk; only the conversions are public.
     *
     * A seller's original photograph can carry EXIF location and a resolution
     * nobody needs. What buyers get is the watermarked, resized, WebP copy —
     * so the file that is served is never the file that was uploaded.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTOS_COLLECTION)
            ->useDisk('local')
            ->storeConversionsOnDisk('public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif']);

        $this->addMediaCollection(self::VIDEO_COLLECTION)
            ->useDisk('local')
            ->storeConversionsOnDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska']);
    }

    /**
     * The image pipeline: resize, WebP, responsive sources.
     *
     * The MonaFindAuto watermark is burnt in afterwards by
     * App\Modules\Catalog\Listeners\WatermarkListingImage, which listens for
     * each conversion finishing. Media library's conversion API has no
     * watermark step of its own, and doing it in a listener keeps the sizes
     * declared here in one readable list.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        /*
         * The conversion's own options come first and the image manipulations
         * after: past the first manipulation the chain is an image, not a
         * conversion, and nonQueued() is not something an image has.
         */
        $this->addMediaConversion('thumb')
            ->performOnCollections(self::PHOTOS_COLLECTION, self::VIDEO_COLLECTION)
            ->nonQueued()
            ->format('webp')
            ->fit(Fit::Contain, 320, 320);

        $this->addMediaConversion('card')
            ->performOnCollections(self::PHOTOS_COLLECTION)
            ->format('webp')
            ->fit(Fit::Contain, 640, 640);

        $this->addMediaConversion('web')
            ->performOnCollections(self::PHOTOS_COLLECTION)
            ->withResponsiveImages()
            ->format('webp')
            ->fit(Fit::Contain, 1400, 1400);
    }

    /**
     * Where the watermark sits on a listing photo.
     *
     * Bottom-right, inset, and translucent: far enough in that a crop cannot
     * remove it without visibly cropping the part, faint enough that it does
     * not hide what the buyer is trying to see.
     */
    public function watermarkPosition(): AlignPosition
    {
        return AlignPosition::BottomRight;
    }

    public function isPublished(): bool
    {
        return $this->status === ListingStatus::Published;
    }

    public function isInspected(): bool
    {
        return $this->inspection_status->isInspected();
    }

    /**
     * Whether a buyer may see this listing.
     *
     * All three halves matter. A published listing under a suspended seller
     * is not visible, because taking a shop down has to take its stock with
     * it — and neither is one nobody has vouched for in a fortnight, because
     * sending a buyer across Lusaka for a part that sold weeks ago is worse
     * than showing them nothing.
     */
    public function isVisibleToBuyers(): bool
    {
        return $this->status->isVisibleToBuyers()
            && $this->freshness_state->isVisibleToBuyers()
            && $this->seller->verification_status->isPubliclyVisible();
    }

    /**
     * Whole days since the seller last confirmed this stock.
     *
     * A listing that has never been confirmed counts from when it was
     * published: putting a part up for sale is itself a claim to have it.
     */
    public function daysSinceStockConfirmed(): int
    {
        $confirmedAt = $this->freshness_confirmed_at ?? $this->published_at ?? $this->created_at;

        return $confirmedAt === null ? 0 : (int) $confirmedAt->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * Whether the seller owes this listing a stock confirmation.
     */
    public function needsStockConfirmation(): bool
    {
        return $this->freshness_state->needsConfirmation();
    }

    /**
     * The cheapest variant's price — what a card shows.
     */
    public function fromPrice(): ?Money
    {
        $prices = $this->variants->pluck('price')->filter();

        if ($prices->isEmpty()) {
            return null;
        }

        return $prices->reduce(
            static fn (?Money $cheapest, Money $price): Money => $cheapest === null || $price->lessThan($cheapest) ? $price : $cheapest,
        );
    }

    /**
     * Whether the listing has stock on any variant.
     */
    public function hasStock(): bool
    {
        return $this->variants->contains(static fn (ProductVariant $variant): bool => $variant->quantity > 0);
    }

    /**
     * Whether the seller ever declared options, or just a price.
     */
    public function hasMultipleVariants(): bool
    {
        return $this->variants->count() > 1;
    }

    /**
     * "Toyota Hilux 2005–2015", or null for a universal part.
     */
    public function fitmentSummary(): ?string
    {
        $vehicle = trim(($this->make->name ?? '').' '.($this->vehicleModel->name ?? ''));

        return trim($vehicle.' '.($this->yearRange() ?? '')) ?: null;
    }

    /**
     * "2005", "2005–2015", or null when the seller did not say.
     */
    public function yearRange(): ?string
    {
        if ($this->year_from === null) {
            return null;
        }

        return $this->year_to === null || $this->year_to === $this->year_from
            ? (string) $this->year_from
            : $this->year_from.'–'.$this->year_to;
    }

    /**
     * Listings a buyer may see: published, fresh enough, from a shop that is
     * still up.
     *
     * The freshness clause is here rather than in a filter the storefront
     * remembers to apply, because a listing that only looks gone is a listing
     * somebody will eventually order from. Every storefront and API query
     * goes through this scope.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ListingStatus::Published)
            ->whereIn('freshness_state', FreshnessState::visibleValues())
            ->whereIn('seller_id', Seller::query()->select('id')->publiclyVisible());
    }

    /**
     * Everything a listing CARD reads, eager-loaded.
     *
     * `ProductCardResource` is rendered twenty-five at a time on search
     * results, category pages and shopfronts, and it touches six relations —
     * including `make` and `vehicleModel`, which are easy to miss because
     * they are reached indirectly through `fitmentSummary()`.
     *
     * Every call site that paginates cards goes through this scope, so the
     * list of what a card needs has one definition rather than six. Adding a
     * relation to the resource means adding it here; forgetting to is caught
     * by `Model::preventLazyLoading()`, which is on everywhere but production.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWithCardRelations(Builder $query): void
    {
        $query->with([
            'seller.city',
            'category',
            'variants',
            'media',
            /* Read by fitmentSummary(), not by the resource directly. */
            'make',
            'vehicleModel',
        ]);
    }

    /**
     * Listings whose stock nobody has confirmed within a given number of days.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStockConfirmedBefore(Builder $query, DateTimeInterface $cutoff): void
    {
        $query->where(function (Builder $query) use ($cutoff): void {
            $query->where('freshness_confirmed_at', '<', $cutoff)
                ->orWhereNull('freshness_confirmed_at');
        });
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingModeration(Builder $query): void
    {
        $query->where('status', ListingStatus::PendingReview)->oldest('submitted_at');
    }

    /**
     * Everything in a category and every category beneath it.
     *
     * Browsing "Engine" has to return injectors, not nothing — which is what
     * the materialised path on Category is for.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInCategory(Builder $query, Category $category): void
    {
        $query->whereIn(
            'category_id',
            Category::query()->select('id')->inSubtreeOf($category),
        );
    }

    /**
     * Free-text search over what a seller types into their own product list.
     *
     * Meilisearch answers the storefront; this is the portal's own filter box
     * and stays in SQL so a seller's drafts are searchable before they have
     * ever been indexed.
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
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('part_number', 'like', "%{$term}%")
                ->orWhere('oem_number', 'like', "%{$term}%");
        });
    }

    /**
     * A slug nobody else holds.
     */
    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'listing';
        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }
}
