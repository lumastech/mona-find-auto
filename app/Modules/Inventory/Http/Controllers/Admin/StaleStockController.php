<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\StaleStockDirectory;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shops that have stopped confirming their stock.
 *
 * Read-only, and deliberately so. Staff cannot confirm stock on a seller's
 * behalf from here — a confirmation means "I have looked on the shelf", and
 * a moderator pressing it for somebody would turn the platform's freshness
 * badge into a lie. The screen exists so somebody can make the phone call.
 */
class StaleStockController extends Controller
{
    public function __construct(private readonly StaleStockDirectory $directory) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Seller::class);

        return Inertia::render('admin/inventory/StaleStock', [
            'sellers' => $this->directory->paginate()->through(fn (Seller $seller): array => [
                'id' => $seller->id,
                'name' => $seller->business_name,
                'slug' => $seller->slug,
                'city' => $seller->getAttribute('city_name'),
                'stale_listings_count' => (int) $seller->getAttribute('stale_listings_count'),
                'hidden_listings_count' => (int) $seller->getAttribute('hidden_listings_count'),
                'href' => route('admin.sellers.show', $seller),
            ]),
            'thresholds' => [
                'ageing' => settings()->integer('freshness.ageing_max_days', 5),
                'hidden' => settings()->integer('freshness.hidden_after_days', 14),
            ],
        ]);
    }
}
