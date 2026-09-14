<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\VehicleModel;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the storefront header needs to let a buyer start browsing.
 *
 * Two things live here because they answer the same question from opposite
 * ends: "I know the part" (the category menu) and "I know the car" (the
 * make/model/year picker). Both are on every storefront page, so both are
 * built once, cached, and shared as one Inertia prop rather than fetched per
 * page — a menu that costs a query on every request is a menu that costs a
 * query on every request forever.
 *
 * The whole payload is small enough to ship with the page: the catalogue is
 * a three-level tree of which the menu shows two, and Zambian road traffic is
 * a few dozen makes. Shipping the models with it means choosing a make does
 * not cost a round trip on mobile data, which is the point. MODEL_LIMIT is
 * the guard — if the reference list ever outgrows it the picker has to become
 * an endpoint, and the count is where that shows up first.
 *
 * Reference data changes when staff curate it, which is rare and audited, so
 * the cache is flushed by the admin controllers that write it rather than
 * expiring on a short timer.
 */
class StorefrontNavigation
{
    public const CACHE_KEY = 'catalog.storefront-navigation';

    /** A day; the flush below is what actually keeps this current. */
    public const CACHE_TTL = 86400;

    /**
     * Above this the payload stops being cheap enough to ship with the page.
     */
    public const MODEL_LIMIT = 600;

    public function __construct(private readonly CategoryTree $categories) {}

    /**
     * @return array{
     *     categories: array<int, array<string, mixed>>,
     *     makes: array<int, array<string, mixed>>,
     *     years: array<int, int>
     * }
     */
    public function payload(): array
    {
        /** @var array{categories: array<int, array<string, mixed>>, makes: array<int, array<string, mixed>>, years: array<int, int>} */
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => [
            'categories' => $this->menuTree(),
            'makes' => $this->makes(),
            'years' => $this->years(),
        ]);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Roots and their immediate children only.
     *
     * The tree is three levels deep, but a three-level fly-out is unusable
     * with a thumb. The third level is reached from the category page, where
     * there is room to show it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function menuTree(): array
    {
        return array_map(
            static fn (array $root): array => [
                'id' => $root['id'],
                'name' => $root['name'],
                'slug' => $root['slug'],
                'children' => array_map(
                    static fn (array $child): array => [
                        'id' => $child['id'],
                        'name' => $child['name'],
                        'slug' => $child['slug'],
                    ],
                    $root['children'],
                ),
            ],
            $this->categories->nested(activeOnly: true),
        );
    }

    /**
     * Makes with their models, in the order the pickers show them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function makes(): array
    {
        $models = VehicleModel::query()
            ->selectable()
            ->limit(self::MODEL_LIMIT)
            ->get(['id', 'make_id', 'name', 'slug', 'production_start_year', 'production_end_year'])
            ->groupBy('make_id');

        return Make::query()
            ->selectable()
            ->get(['id', 'name', 'slug', 'is_popular'])
            ->map(static fn (Make $make): array => [
                'id' => $make->id,
                'name' => $make->name,
                'slug' => $make->slug,
                'is_popular' => $make->is_popular,
                'models' => $models->get($make->id, collect())
                    ->map(static fn (VehicleModel $model): array => [
                        'id' => $model->id,
                        'name' => $model->name,
                        'slug' => $model->slug,
                        'start_year' => $model->production_start_year,
                        'end_year' => $model->production_end_year,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The year range the picker offers when a model declares none.
     *
     * Newest first: a buyer is far more often looking for a recent vehicle
     * than a 1990 one, and scrolling up a select on a phone is the cost.
     *
     * @return array<int, int>
     */
    private function years(): array
    {
        $latest = (int) now()->year + 1;

        return range($latest, $latest - 40);
    }
}
