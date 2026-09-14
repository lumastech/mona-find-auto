<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Services\FreshnessService;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One-tap stock confirmation, from the mobile app.
 *
 * The web portal's most important button, mirrored: a seller in their yard
 * with a phone is the person this whole scheme is asking to press it.
 */
class StockConfirmationController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(private readonly FreshnessService $freshness) {}

    /**
     * Confirm the whole shop.
     */
    public function store(Request $request): JsonResponse
    {
        $seller = $this->currentSeller($request);

        $confirmed = $this->freshness->confirmAllFor($seller, $this->currentUser($request));

        return ApiResponse::ok([
            'confirmed_listings' => $confirmed,
            'outstanding' => $this->freshness->outstandingFor($seller),
        ]);
    }

    /**
     * Confirm one listing.
     */
    public function storeForProduct(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('update', $product);

        $this->freshness->confirm($product, $this->currentUser($request));

        return ApiResponse::ok([
            'product_id' => $product->getKey(),
            'freshness_state' => $product->freshness_state->value,
            'confirmed_at' => $product->freshness_confirmed_at?->toIso8601String(),
        ]);
    }
}
