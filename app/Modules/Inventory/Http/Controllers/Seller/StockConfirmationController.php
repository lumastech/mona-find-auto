<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Services\FreshnessService;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "All stock accurate" — the single most important button in the portal.
 *
 * The whole freshness scheme rests on this being one tap. A confirmation flow
 * that asks a seller to tick four hundred boxes is a confirmation flow that
 * stops happening in week two, and then every listing on the platform slides
 * to Unconfirmed and the label stops meaning anything.
 *
 * Per-listing confirmation exists beside it for the seller who genuinely only
 * wants to vouch for some of their stock.
 */
class StockConfirmationController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(private readonly FreshnessService $freshness) {}

    /**
     * Confirm the whole shop.
     */
    public function store(Request $request): RedirectResponse
    {
        $seller = $this->currentSeller($request);

        $confirmed = $this->freshness->confirmAllFor($seller, $this->currentUser($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{0}There was nothing waiting to be confirmed.|{1}One listing confirmed. Thank you.|[2,*]:count listings confirmed. Thank you.',
                $confirmed,
                ['count' => $confirmed],
            ),
        ]);

        return back();
    }

    /**
     * Confirm one listing.
     */
    public function storeForProduct(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $this->freshness->confirm($product, $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock confirmed.')]);

        return back();
    }
}
