<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Inventory\Http\Resources\StockListingResource;
use App\Modules\Inventory\Services\FreshnessService;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A seller's own stock, for the mobile app.
 *
 * This is the endpoint that matters most on a phone: a seller standing in
 * their yard with the app open is exactly who the freshness scheme is aimed
 * at, and confirming from there is a great deal more likely than confirming
 * from a desk they visit twice a week.
 */
class StockController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(private readonly FreshnessService $freshness) {}

    /**
     * The seller's listings with their stock and freshness position.
     */
    public function index(Request $request): JsonResponse
    {
        $seller = $this->currentSeller($request);

        $filters = $request->validate([
            'freshness' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $listings = $this->freshness->sweepable()
            ->where('seller_id', $seller->getKey())
            ->with(['variants', 'media'])
            ->search($filters['search'] ?? null)
            ->when(
                FreshnessState::tryFrom($filters['freshness'] ?? ''),
                fn ($query, FreshnessState $state) => $query->where('freshness_state', $state),
            )
            ->orderBy('freshness_confirmed_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Product $product): array => StockListingResource::make($product)->resolve($request));

        return ApiResponse::paginated($listings, [
            'outstanding' => $this->freshness->outstandingFor($seller),
        ]);
    }
}
