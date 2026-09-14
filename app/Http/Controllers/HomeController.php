<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Http\Resources\ProductCardResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Mechanics\Http\Resources\MechanicProfileResource;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Services\MechanicDirectory;
use App\Modules\Mechanics\Support\DirectoryFilters;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The front door.
 *
 * The one storefront page that belongs to no module, because it is the place
 * the modules are introduced to each other: Catalog's headings and listings,
 * Mechanics' directory, the Sellers count behind the trust claims. It reads
 * each module's public surface — the same scopes and resources their own
 * controllers use — rather than reaching past it, so a rule like "hidden
 * stock never reaches a buyer" is enforced in one place for this page too.
 *
 * Two rails carry the platform's own promises rather than whatever is newest:
 * inspected listings, because the gold badge is the thing a buyer has to
 * learn to read, and recently confirmed stock, because rewarding the sellers
 * who confirm is how the freshness model stays honest.
 *
 * Server-rendered. The counts are cached; the listings are not, because a
 * homepage advertising a part that sold this morning is the one kind of
 * staleness this page cannot afford.
 */
class HomeController extends Controller
{
    /** Two rows of four on a desktop grid, one column on a phone. */
    private const RAIL_SIZE = 8;

    /** Enough headings to browse by, few enough to read at a glance. */
    private const CATEGORY_COUNT = 8;

    private const COUNTS_CACHE_KEY = 'storefront.home.counts';

    private const COUNTS_CACHE_TTL = 600;

    public function __construct(private readonly MechanicDirectory $mechanics) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('storefront/Home', [
            'categories' => $this->categoryCounts(),
            'inspected' => $this->inspectedListings($request),
            'freshlyConfirmed' => $this->freshlyConfirmedListings($request),
            'mechanics' => $this->featuredMechanics($request),
            'stats' => $this->stats(),
        ]);
    }

    /**
     * The catalogue's headings, each with what is actually under it.
     *
     * "Under it" means the whole subtree — browsing Engine has to include
     * injectors — which is what Category's materialised path is for. A
     * heading with nothing beneath it is dropped rather than shown as zero:
     * an empty category is a dead end a buyer should not be offered.
     *
     * @return array<int, array{id: int, name: string, slug: string, listings: int}>
     */
    private function categoryCounts(): array
    {
        /** @var array<int, array{id: int, name: string, slug: string, listings: int}> */
        return Cache::remember(
            self::COUNTS_CACHE_KEY.'.categories',
            self::COUNTS_CACHE_TTL,
            function (): array {
                $roots = Category::query()
                    ->where('depth', 0)
                    ->where('is_active', true)
                    ->inTreeOrder()
                    ->get();

                return $roots
                    ->map(static fn (Category $root): array => [
                        'id' => $root->id,
                        'name' => $root->name,
                        'slug' => $root->slug,
                        'listings' => Product::query()->published()->inCategory($root)->count(),
                    ])
                    ->filter(static fn (array $row): bool => $row['listings'] > 0)
                    ->sortByDesc('listings')
                    ->take(self::CATEGORY_COUNT)
                    ->values()
                    ->all();
            },
        );
    }

    /**
     * Listings MonaFind has physically checked, newest inspection first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function inspectedListings(Request $request): array
    {
        return Product::query()
            ->published()
            ->where('inspection_status', InspectionStatus::Inspected)
            ->withCardRelations()
            ->orderByDesc('inspected_at')
            ->limit(self::RAIL_SIZE)
            ->get()
            ->map(fn (Product $product): array => ProductCardResource::make($product)->resolve($request))
            ->all();
    }

    /**
     * Stock a seller has confirmed most recently.
     *
     * Ordered by the confirmation rather than the listing date, which is the
     * whole point: the reward for one-tap confirming is being seen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function freshlyConfirmedListings(Request $request): array
    {
        return Product::query()
            ->published()
            ->withCardRelations()
            ->orderByDesc('freshness_confirmed_at')
            ->limit(self::RAIL_SIZE)
            ->get()
            ->map(fn (Product $product): array => ProductCardResource::make($product)->resolve($request))
            ->all();
    }

    /**
     * Three endorsed mechanics, best rated first.
     *
     * Through the directory rather than the model, so the "approved profiles
     * only" rule stays in the one place that owns it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function featuredMechanics(Request $request): array
    {
        return collect(
            $this->mechanics->search(new DirectoryFilters(endorsedOnly: true), perPage: 3)->items(),
        )
            ->map(fn (MechanicProfile $profile): array => MechanicProfileResource::make($profile)->resolve($request))
            ->all();
    }

    /**
     * The numbers behind the trust strip.
     *
     * Rounded down to a round figure by the browser, not here — the page
     * decides how to phrase "over 200"; this decides what is true.
     *
     * @return array{verified_sellers: int, listings: int, inspected: int}
     */
    private function stats(): array
    {
        /** @var array{verified_sellers: int, listings: int, inspected: int} */
        return Cache::remember(
            self::COUNTS_CACHE_KEY.'.stats',
            self::COUNTS_CACHE_TTL,
            static fn (): array => [
                'verified_sellers' => Seller::query()->verified()->count(),
                'listings' => Product::query()->published()->count(),
                'inspected' => Product::query()
                    ->published()
                    ->where('inspection_status', InspectionStatus::Inspected)
                    ->count(),
            ],
        );
    }
}
