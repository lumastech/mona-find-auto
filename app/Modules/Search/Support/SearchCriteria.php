<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Search\Enums\SearchSort;
use App\Modules\Sellers\Enums\SellerType;

/**
 * One search, as the buyer asked for it.
 *
 * A value object rather than an array passed around, because the storefront
 * page, the JSON API and the analytics row all have to agree about what was
 * searched, and three places reading `$request->input('inspected')` is three
 * places to disagree about whether "0" means no.
 *
 * The location fields are the interesting ones. A buyer's coordinates are
 * only ever here because they shared them or typed a town, and they only
 * affect the result when the buyer picked Nearest or set a radius — never in
 * the default order.
 */
final readonly class SearchCriteria
{
    public const DEFAULT_PER_PAGE = 24;

    public const MAX_PER_PAGE = 48;

    /** Far enough to cover a whole Zambian province, in kilometres. */
    public const MAX_RADIUS_KM = 500;

    public function __construct(
        public ?string $query = null,
        public ?int $categoryId = null,
        public ?int $makeId = null,
        public ?int $vehicleModelId = null,
        public ?int $year = null,
        public ?Condition $condition = null,
        public bool $inspectedOnly = false,
        public ?PartSourcing $sourcing = null,
        public ?int $minPriceNgwee = null,
        public ?int $maxPriceNgwee = null,
        public ?SellerType $sellerType = null,
        public bool $verifiedOnly = false,
        public bool $deliveryOnly = false,
        public bool $inStockOnly = false,
        public ?int $provinceId = null,
        public ?int $cityId = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $radiusKm = null,
        public SearchSort $sort = SearchSort::Recommended,
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    /**
     * Build criteria from a validated request payload.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $latitude = self::float($input['lat'] ?? null);
        $longitude = self::float($input['lng'] ?? null);

        $sort = SearchSort::tryFrom((string) ($input['sort'] ?? '')) ?? SearchSort::default();

        return new self(
            query: self::text($input['q'] ?? null),
            categoryId: self::int($input['category_id'] ?? null),
            makeId: self::int($input['make_id'] ?? null),
            vehicleModelId: self::int($input['vehicle_model_id'] ?? null),
            year: self::int($input['year'] ?? null),
            condition: Condition::tryFrom((string) ($input['condition'] ?? '')),
            inspectedOnly: (bool) ($input['inspected'] ?? false),
            sourcing: PartSourcing::tryFrom((string) ($input['sourcing'] ?? '')),
            minPriceNgwee: self::int($input['min_price'] ?? null),
            maxPriceNgwee: self::int($input['max_price'] ?? null),
            sellerType: SellerType::tryFrom((string) ($input['seller_type'] ?? '')),
            verifiedOnly: (bool) ($input['verified'] ?? false),
            deliveryOnly: (bool) ($input['delivery'] ?? false),
            inStockOnly: (bool) ($input['in_stock'] ?? false),
            provinceId: self::int($input['province_id'] ?? null),
            cityId: self::int($input['city_id'] ?? null),
            latitude: $latitude,
            longitude: $longitude,
            radiusKm: self::int($input['radius_km'] ?? null),
            /* Asking to be sorted by distance without a location is answered, not refused. */
            sort: $sort->resolvedFor($latitude, $longitude),
            page: max(1, self::int($input['page'] ?? null) ?? 1),
            perPage: min(self::MAX_PER_PAGE, max(1, self::int($input['per_page'] ?? null) ?? self::DEFAULT_PER_PAGE)),
        );
    }

    public function tokens(): SearchTokens
    {
        return SearchTokens::of($this->query);
    }

    public function hasQuery(): bool
    {
        return ! $this->tokens()->isEmpty();
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Whether the buyer narrowed anything at all beyond typing.
     */
    public function hasFilters(): bool
    {
        return $this->appliedFilters() !== [];
    }

    /**
     * The same criteria with the free text dropped and one category forced —
     * the tier-3 fallback query.
     */
    public function fallingBackTo(int $categoryId): self
    {
        return new self(
            query: null,
            categoryId: $categoryId,
            makeId: $this->makeId,
            vehicleModelId: null,
            year: null,
            condition: $this->condition,
            inspectedOnly: $this->inspectedOnly,
            sourcing: null,
            minPriceNgwee: $this->minPriceNgwee,
            maxPriceNgwee: $this->maxPriceNgwee,
            sellerType: $this->sellerType,
            verifiedOnly: $this->verifiedOnly,
            deliveryOnly: $this->deliveryOnly,
            inStockOnly: $this->inStockOnly,
            provinceId: $this->provinceId,
            cityId: $this->cityId,
            latitude: $this->latitude,
            longitude: $this->longitude,
            radiusKm: $this->radiusKm,
            sort: $this->sort,
            page: $this->page,
            perPage: $this->perPage,
        );
    }

    /**
     * The narrowing the buyer applied, for the chips above the results and
     * for the analytics row. Nulls and falses are absent rather than present
     * and empty, so a zero-result report can be grouped on it.
     *
     * @return array<string, mixed>
     */
    public function appliedFilters(): array
    {
        return array_filter([
            'category_id' => $this->categoryId,
            'make_id' => $this->makeId,
            'vehicle_model_id' => $this->vehicleModelId,
            'year' => $this->year,
            'condition' => $this->condition?->value,
            'inspected' => $this->inspectedOnly ?: null,
            'sourcing' => $this->sourcing?->value,
            'min_price' => $this->minPriceNgwee,
            'max_price' => $this->maxPriceNgwee,
            'seller_type' => $this->sellerType?->value,
            'verified' => $this->verifiedOnly ?: null,
            'delivery' => $this->deliveryOnly ?: null,
            'in_stock' => $this->inStockOnly ?: null,
            'province_id' => $this->provinceId,
            'city_id' => $this->cityId,
            'radius_km' => $this->hasLocation() ? $this->radiusKm : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The query string the storefront echoes back into its own links.
     *
     * @return array<string, mixed>
     */
    public function toQueryString(): array
    {
        return array_filter([
            'q' => $this->query,
            ...$this->appliedFilters(),
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'sort' => $this->sort === SearchSort::Recommended ? null : $this->sort->value,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function float(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
