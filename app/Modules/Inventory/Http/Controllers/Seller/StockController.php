<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Http\Requests\Seller\StockUpdateRequest;
use App\Modules\Inventory\Http\Resources\StockListingResource;
use App\Modules\Inventory\Models\StockImportBatch;
use App\Modules\Inventory\Services\FreshnessService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\StockSummary;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The stock screen: what a seller has, and how long since they said so.
 *
 * Ordered worst-first by default. A seller who opens this once a week wants
 * the listings that are about to be hidden at the top, not the ones they
 * confirmed this morning — so the sort is by freshness and then by how empty
 * the shelf is.
 */
class StockController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    /**
     * Worst freshness first.
     *
     * Written out as SQL rather than sorted in PHP because the list is
     * paginated: sorting a page of twenty-five would put the wrong
     * twenty-five on it. Spelt out literally rather than built from the enum
     * so it stays a literal string — but the states are the enum's, and a new
     * one added there belongs here too.
     */
    private const WORST_FRESHNESS_FIRST = 'CASE freshness_state'
        ." WHEN 'hidden' THEN 0"
        ." WHEN 'unconfirmed' THEN 1"
        ." WHEN 'ageing' THEN 2"
        ." WHEN 'fresh' THEN 3"
        .' ELSE 99 END';

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly FreshnessService $freshness,
        private readonly StockSummary $summary,
    ) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        $filters = $request->validate([
            'freshness' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'attention' => ['nullable', 'boolean'],
        ]);

        $listings = $this->freshness->sweepable()
            ->where('seller_id', $seller->getKey())
            ->with(['variants', 'media'])
            ->search($filters['search'] ?? null)
            ->when(
                FreshnessState::tryFrom($filters['freshness'] ?? ''),
                fn ($query, FreshnessState $state) => $query->where('freshness_state', $state),
            )
            /*
             * "Needs me" — anything unconfirmed, low or empty, in one filter.
             *
             * The two stock clauses are subqueries off ProductVariant rather
             * than whereHas closures so that the low-stock rule stays in the
             * one scope that knows how a null threshold falls back to the
             * platform default.
             */
            ->when(
                $filters['attention'] ?? false,
                fn (Builder $query) => $query->where(function (Builder $query): void {
                    $query->whereNot('freshness_state', FreshnessState::Fresh)
                        ->orWhereIn('id', ProductVariant::query()->select('product_id')->lowStock())
                        ->orWhereIn('id', ProductVariant::query()->select('product_id')->outOfStock());
                }),
            )
            ->orderByRaw(self::WORST_FRESHNESS_FIRST)
            ->orderBy('freshness_confirmed_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Product $product): array => StockListingResource::make($product)->resolve($request));

        return Inertia::render('seller/stock/Index', [
            'listings' => $listings,
            'filters' => $filters,
            'freshnessStates' => FreshnessState::options(),
            'summary' => $this->summary->for($seller),
            'latestImport' => $this->latestImportId($request),
        ]);
    }

    /**
     * Correct one option's quantity, or its low-stock threshold.
     *
     * The quantity goes through the ledger like every other stock change, so
     * a seller's own correction is locked, recorded and able to trigger the
     * back-in-stock notifications a buyer is waiting on. The threshold does
     * not — it is a preference, not stock.
     */
    public function update(StockUpdateRequest $request, Product $product): RedirectResponse
    {
        $variant = ProductVariant::query()->findOrFail($request->integer('variant_id'));
        $actor = $this->currentUser($request);

        if ($request->has('low_stock_threshold')) {
            $variant->forceFill([
                'low_stock_threshold' => $request->input('low_stock_threshold') === null
                    ? null
                    : $request->integer('low_stock_threshold'),
            ])->save();
        }

        $this->ledger->setQuantity(
            $variant,
            $request->integer('quantity'),
            StockMovementReason::SellerAdjustment,
            $actor,
        );

        /*
         * Typing a quantity is a statement about the shelf, so it counts as
         * confirming it. Making the seller do both would be asking the same
         * question twice.
         */
        $this->freshness->confirm($product, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock updated.')]);

        return back();
    }

    /**
     * The batch still waiting on a decision, so the page can link straight to
     * its report rather than making the seller hunt for it.
     */
    private function latestImportId(Request $request): ?int
    {
        $batch = StockImportBatch::query()
            ->where('seller_id', $this->currentSeller($request)->getKey())
            ->awaitingConfirmation()
            ->latest('id')
            ->first();

        return $batch?->getKey();
    }
}
