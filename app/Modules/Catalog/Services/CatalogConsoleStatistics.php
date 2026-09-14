<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use App\Support\Console\ConsoleStat;
use App\Support\Console\ConsoleStatDirection;
use App\Support\Console\ConsoleWindow;
use App\Support\Console\DailySeries;
use App\Support\Console\ProvidesConsoleStatistics;

/**
 * What the staff console's home screen says about the catalogue.
 *
 * Both figures are read off `published_at` rather than `created_at`. A part
 * drafted in August and published in September is September's catalogue
 * growth — it is the moment a buyer could first find it that matters, and
 * that is the only one of the two dates a moderator's own work moves.
 *
 * "Live listings" carries no comparison: it is a count of what is on the
 * storefront right now, not something that happened during the window, and a
 * trend arrow on a standing total would be reading the wrong thing.
 */
class CatalogConsoleStatistics implements ProvidesConsoleStatistics
{
    public function stats(ConsoleWindow $window): array
    {
        return [
            new ConsoleStat(
                key: 'catalog.published',
                label: 'Listings published',
                value: $this->publishedIn($window),
                previous: $this->publishedIn($window->previous()),
                direction: ConsoleStatDirection::HigherIsBetter,
                href: route('admin.listings.index', ['status' => ListingStatus::Published->value]),
                ability: 'moderate',
                hint: 'Parts that became findable during the window.',
                spark: DailySeries::count(Product::query(), 'published_at', $window),
            ),
            new ConsoleStat(
                key: 'catalog.live',
                label: 'Live listings',
                value: Product::query()->published()->count(),
                previous: null,
                direction: ConsoleStatDirection::Neutral,
                href: route('admin.listings.index', ['status' => ListingStatus::Published->value]),
                ability: 'moderate',
                hint: 'On the storefront right now, stale stock excluded.',
            ),
        ];
    }

    public function charts(ConsoleWindow $window): array
    {
        return [];
    }

    private function publishedIn(ConsoleWindow $window): int
    {
        return Product::query()->whereBetween('published_at', $window->bounds())->count();
    }
}
