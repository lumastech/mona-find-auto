<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Services\ProductDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use LogicException;

/**
 * The `sql` Scout driver: listings indexed into the application's own
 * database, for hosting that cannot run Meilisearch.
 *
 * Only the writing half matters. Every existing path into the index — the
 * model observer, Services\ListingIndexer, the nightly rebuild — goes through
 * Scout, so pointing Scout here is all it takes to keep `search_listings` in
 * step. Buyers' searches do not come through Scout at all; they are run by
 * Services\SqlListingSearch, which needs facets and ordering Scout's builder
 * cannot express. The search methods below exist because the contract
 * demands them, and match every word, nothing more.
 *
 * Scout's own `database` driver is not a substitute: it searches the
 * products table's own columns and builds its column list by calling
 * toSearchableArray() on an empty model, which cannot describe a listing
 * that has no seller.
 */
final class SqlSearchEngine extends Engine
{
    public const DRIVER = 'sql';

    public function __construct(private readonly ProductDocument $documents) {}

    /**
     * @param  Collection<int, Model>  $models
     */
    public function update($models): void
    {
        $rows = [];
        $hidden = [];

        foreach ($models as $model) {
            $product = $this->listing($model);

            /*
             * Scout hands update() whatever it was given, visible or not. A
             * table that buyers' searches read directly cannot afford that.
             */
            if (! $product->shouldBeSearchable()) {
                $hidden[] = $product->getKey();

                continue;
            }

            $document = $product->toSearchableArray();

            if ($document !== []) {
                $rows[] = SqlListingRow::from($document, $this->documents->matchText($product));
            }
        }

        if ($hidden !== []) {
            DB::table(SqlListingRow::TABLE)->whereIn('product_id', $hidden)->delete();
        }

        if ($rows === []) {
            return;
        }

        DB::table(SqlListingRow::TABLE)->upsert(
            $rows,
            ['product_id'],
            array_values(array_diff(array_keys($rows[0]), ['product_id'])),
        );
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        DB::table(SqlListingRow::TABLE)
            ->whereIn('product_id', $models->map(fn (Model $model): mixed => $this->listing($model)->getScoutKey())->all())
            ->delete();
    }

    /**
     * @param  Builder<Model>  $builder
     * @return array{ids: array<int, int>, total: int}
     */
    public function search(Builder $builder): array
    {
        return $this->performSearch($builder, $builder->limit);
    }

    /**
     * @param  Builder<Model>  $builder
     * @return array{ids: array<int, int>, total: int}
     */
    public function paginate(Builder $builder, $perPage, $page): array
    {
        return $this->performSearch($builder, (int) $perPage, (int) $page);
    }

    /**
     * @param  array{ids: array<int, int>, total: int}  $results
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function mapIds($results): \Illuminate\Support\Collection
    {
        return collect($results['ids']);
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  array{ids: array<int, int>, total: int}  $results
     * @param  Model  $model
     * @return Collection<int, Model>
     */
    public function map(Builder $builder, $results, $model): Collection
    {
        $ids = $results['ids'];

        if ($ids === []) {
            return $model->newCollection();
        }

        $positions = array_flip($ids);

        return $this->listing($model)->getScoutModelsByIds($builder, $ids)
            ->filter(static fn (Model $model): bool => isset($positions[$model->getKey()]))
            ->sortBy(static fn (Model $model): int => $positions[$model->getKey()])
            ->values();
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  array{ids: array<int, int>, total: int}  $results
     * @param  Model  $model
     * @return LazyCollection<int, Model>
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        return LazyCollection::make($this->map($builder, $results, $model)->all());
    }

    /**
     * @param  array{ids: array<int, int>, total: int}  $results
     */
    public function getTotalCount($results): int
    {
        return $results['total'];
    }

    /**
     * @param  Model  $model
     */
    public function flush($model): void
    {
        DB::table(SqlListingRow::TABLE)->delete();
    }

    /**
     * The table is a migration; there is nothing to create.
     *
     * @param  string  $name
     * @param  array<string, mixed>  $options
     */
    public function createIndex($name, array $options = []): mixed
    {
        return null;
    }

    /**
     * @param  string  $name
     */
    public function deleteIndex($name): mixed
    {
        return null;
    }

    /**
     * @param  Builder<Model>  $builder
     * @return array{ids: array<int, int>, total: int}
     */
    private function performSearch(Builder $builder, ?int $perPage = null, int $page = 1): array
    {
        $query = DB::table(SqlListingRow::TABLE);

        foreach (SearchTokens::of($builder->query)->tokens as $token) {
            $query->where('search_text', 'like', '% '.$token.'%');
        }

        $total = (clone $query)->count();

        $query->orderByDesc('quality_hundredths')->orderByDesc('product_id');

        if ($perPage !== null) {
            $query->forPage($page, $perPage);
        }

        return [
            'ids' => $query->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'total' => $total,
        ];
    }

    private function listing(Model $model): Product
    {
        if (! $model instanceof Product) {
            throw new LogicException('The sql search driver only indexes listings, not '.$model::class.'.');
        }

        return $model;
    }
}
