<?php

declare(strict_types=1);

namespace App\Modules\Search\Concerns;

use App\Modules\Search\Services\ProductDocument;
use App\Modules\Search\Support\ProductIndex;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Laravel\Scout\Searchable;

/**
 * The one line Catalog's Product gives up to be searchable.
 *
 * Scout indexes Eloquent models, so something has to be added to the model
 * itself — there is no arrangement in which Search owns a class Catalog does
 * not know about. This trait is the seam: Catalog says `use
 * SearchableListing`, and every decision about what gets indexed, under what
 * name, and when a listing belongs in the index at all stays inside the
 * Search module, where it can change without reopening Catalog.
 */
trait SearchableListing
{
    use Searchable;

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return app(ProductDocument::class)->for($this);
    }

    /**
     * One index for the whole catalogue, named by Search rather than derived
     * from the table, so renaming a table cannot silently orphan an index.
     */
    public function searchableAs(): string
    {
        return config('scout.prefix').ProductIndex::NAME;
    }

    /**
     * Only what a buyer may actually see.
     *
     * This is the same question the storefront asks — published, stock not
     * gone stale, seller still standing — so a listing that has fallen out of
     * view falls out of the index on its next save rather than lingering as
     * a result nobody can open.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->isVisibleToBuyers();
    }

    /**
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    protected function makeAllSearchableUsing(EloquentBuilder $query): EloquentBuilder
    {
        return $query->with(ProductDocument::relations());
    }
}
