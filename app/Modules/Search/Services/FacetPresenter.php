<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\VehicleModel;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use App\Modules\Sellers\Enums\SellerType;

/**
 * Turning Meilisearch's facet counts into a sidebar a buyer can read.
 *
 * Meilisearch answers with ids and raw counts: `make_id => ['4' => 91]`. The
 * sidebar needs "Toyota (91)", ordered so the useful options are at the top
 * of a phone screen rather than buried alphabetically at the bottom.
 *
 * Facets with a zero count are dropped rather than shown greyed out. On a
 * 4-inch screen over a slow connection, a list of forty makes that would all
 * return nothing is worse than no list.
 */
final class FacetPresenter
{
    /**
     * How many values of one facet the sidebar shows before "show more".
     * Enough to cover the makes that matter in Zambia without scrolling past
     * the price filter.
     */
    private const MAX_VALUES = 30;

    /**
     * @param  array<string, array<string, int>>  $distribution
     * @return array<string, mixed>
     */
    public function present(array $distribution): array
    {
        return [
            'makes' => $this->fromModel(Make::class, $distribution['make_id'] ?? []),
            'vehicle_models' => $this->fromModel(VehicleModel::class, $distribution['vehicle_model_id'] ?? []),
            'categories' => $this->fromModel(Category::class, $distribution['category_id'] ?? []),
            'provinces' => $this->fromModel(Province::class, $distribution['province_id'] ?? []),
            'cities' => $this->fromModel(City::class, $distribution['city_id'] ?? []),

            'conditions' => $this->labelled(
                array_map(
                    static fn (Condition $case): array => ['value' => $case->value, 'label' => $case->label()],
                    Condition::cases(),
                ),
                $distribution['condition'] ?? [],
            ),
            'sourcing' => $this->labelled(
                array_map(
                    static fn (PartSourcing $case): array => ['value' => $case->value, 'label' => $case->label()],
                    PartSourcing::cases(),
                ),
                $distribution['sourcing'] ?? [],
            ),
            'seller_types' => $this->labelled(
                array_map(
                    static fn (SellerType $case): array => ['value' => $case->value, 'label' => $case->label()],
                    SellerType::cases(),
                ),
                $distribution['seller_type'] ?? [],
            ),

            /*
             * The four yes/no filters carry only the count of listings that
             * would survive them — a checkbox does not need a "no" option.
             */
            'inspected' => $this->trueCount($distribution['inspected'] ?? []),
            'verified' => $this->trueCount($distribution['seller_verified'] ?? []),
            'delivery' => $this->trueCount($distribution['delivery_available'] ?? []),
        ];
    }

    /**
     * Reference rows that actually matched, largest count first.
     *
     * @param  class-string<Make|VehicleModel|Category|Province|City>  $model
     * @param  array<string, int>  $counts
     * @return array<int, array{id: int, name: string, count: int}>
     */
    private function fromModel(string $model, array $counts): array
    {
        $counts = array_filter($counts, static fn (int $count): bool => $count > 0);

        if ($counts === []) {
            return [];
        }

        arsort($counts);
        $ids = array_slice(array_keys($counts), 0, self::MAX_VALUES);

        $names = $model::query()->whereKey($ids)->pluck('name', 'id');

        $options = [];

        foreach ($ids as $id) {
            $name = $names[(int) $id] ?? null;

            /* A facet for a row that has since been deleted has nothing to label it. */
            if ($name === null) {
                continue;
            }

            $options[] = ['id' => (int) $id, 'name' => (string) $name, 'count' => $counts[$id]];
        }

        return $options;
    }

    /**
     * Enum-backed facets, in the enum's own declaration order — which is the
     * order a buyer expects to read them in, unlike a count-descending list
     * that reshuffles itself on every keystroke.
     *
     * @param  array<int, array{value: string, label: string}>  $cases
     * @param  array<string, int>  $counts
     * @return array<int, array{value: string, label: string, count: int}>
     */
    private function labelled(array $cases, array $counts): array
    {
        $options = [];

        foreach ($cases as $case) {
            $count = $counts[$case['value']] ?? 0;

            if ($count === 0) {
                continue;
            }

            $options[] = [...$case, 'count' => $count];
        }

        return $options;
    }

    /**
     * Meilisearch reports booleans as the strings "true" and "false".
     *
     * @param  array<string, int>  $counts
     */
    private function trueCount(array $counts): int
    {
        return (int) ($counts['true'] ?? 0);
    }
}
