<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Enums\MatchTier;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One page of results, and everything the page around them needs to explain
 * itself.
 *
 * The tiers are here rather than derived in the view because they are part of
 * the answer: a buyer looking at a screen of partial matches is owed the
 * reason, and the reason was worked out where the query was.
 *
 * @property-read LengthAwarePaginator<int, Product> $listings
 */
final readonly class SearchResults
{
    /**
     * @param  LengthAwarePaginator<int, Product>  $listings
     * @param  array<int, MatchTier>  $tiers  Keyed by listing id.
     * @param  array<string, array<string, int>>  $facets  Meilisearch's facet distribution.
     */
    public function __construct(
        public LengthAwarePaginator $listings,
        public MatchTier $tier,
        public array $tiers = [],
        public array $facets = [],
        public ?Category $fallbackCategory = null,
    ) {}

    public function total(): int
    {
        return $this->listings->total();
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /**
     * What the page says above the results, or null when they speak for
     * themselves.
     */
    public function notice(): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }

        return $this->tier->notice();
    }

    public function tierFor(Product $product): MatchTier
    {
        return $this->tiers[$product->getKey()] ?? $this->tier;
    }
}
