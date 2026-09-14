<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Services\ProductDocument as Documents;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Collection;

/**
 * Putting listings into the index and taking them out again.
 *
 * Scout's own `searchable()` does not consult `shouldBeSearchable()` — it
 * indexes whatever it is handed. That is fine when Eloquent's save event is
 * driving, and wrong everywhere else here, because the events this module
 * reacts to are precisely the ones that change whether a listing belongs in
 * the index at all: stock going stale, a shop being suspended, a listing
 * being unpublished. So every write goes through here, where the question is
 * asked before the answer is acted on.
 */
final class ListingIndexer
{
    /** Rows per chunk on a full rebuild. Matches scout.chunk.searchable. */
    private const CHUNK = 500;

    public function __construct(private readonly Documents $documents) {}

    /**
     * Index one listing, or remove it if buyers may no longer see it.
     */
    public function sync(Product $product): void
    {
        $product->loadMissing(Documents::relations());

        if ($product->isVisibleToBuyers()) {
            $product->searchable();

            return;
        }

        $product->unsearchable();
    }

    /**
     * Re-index everything one seller lists.
     *
     * A seller's verification, name, type and town are all copied onto every
     * one of its listings so the facets can filter without a join — so a
     * change to the shop is a change to its whole catalogue.
     */
    public function syncSeller(Seller $seller): int
    {
        $synced = 0;

        Product::query()
            ->where('seller_id', $seller->getKey())
            ->with(Documents::relations())
            ->chunkById(self::CHUNK, function (Collection $listings) use (&$synced): void {
                $this->syncMany($listings);
                $synced += $listings->count();
            });

        return $synced;
    }

    /**
     * Rebuild the whole index, without ever leaving it empty.
     *
     * Flushing first and importing after would be simpler and would guarantee
     * no orphans, but it would also mean that for the length of the rebuild
     * every buyer on the storefront searches an empty catalogue. Two passes
     * cost more queries and cost nobody a search.
     *
     * @return array{indexed: int, removed: int}
     */
    public function rebuild(?callable $progress = null): array
    {
        $this->documents->forgetReputations();

        $indexed = 0;
        $removed = 0;

        Product::query()
            ->published()
            ->with(Documents::relations())
            ->chunkById(self::CHUNK, function (Collection $listings) use (&$indexed, $progress): void {
                $this->index($listings);
                $indexed += $listings->count();

                if ($progress !== null) {
                    $progress($listings->count());
                }
            });

        /*
         * The second pass is what stops a listing that was unpublished while
         * the queue was down from staying findable for ever.
         */
        Product::query()
            ->whereNotIn('id', Product::query()->select('id')->published())
            ->select(['id'])
            ->chunkById(self::CHUNK, function (Collection $listings) use (&$removed, $progress): void {
                $this->remove($listings);
                $removed += $listings->count();

                if ($progress !== null) {
                    $progress($listings->count());
                }
            });

        return ['indexed' => $indexed, 'removed' => $removed];
    }

    /**
     * @param  Collection<int, Product>  $listings
     */
    private function syncMany(Collection $listings): void
    {
        $this->index($listings->filter(static fn (Product $product): bool => $product->isVisibleToBuyers()));
        $this->remove($listings->reject(static fn (Product $product): bool => $product->isVisibleToBuyers()));
    }

    /**
     * Write a batch of documents.
     *
     * Scout exposes this as a collection macro, which is convenient and
     * invisible to static analysis; the trait method underneath it is the
     * same code path — queued when `scout.queue` is on, immediate when it is
     * not — and can be type-checked.
     *
     * @param  Collection<int, Product>  $listings
     */
    private function index(Collection $listings): void
    {
        if ($listings->isNotEmpty()) {
            (new Product)->queueMakeSearchable($listings);
        }
    }

    /**
     * @param  Collection<int, Product>  $listings
     */
    private function remove(Collection $listings): void
    {
        if ($listings->isNotEmpty()) {
            (new Product)->queueRemoveFromSearch($listings);
        }
    }
}
