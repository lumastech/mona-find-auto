<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\BackInStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * "Tell me when this is back", for the mobile app.
 *
 * Same rule as the web page: the buyer has to be signed in, because there is
 * nowhere to send a notification to somebody the platform cannot identify.
 */
class BackInStockController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly BackInStockService $subscriptions) {}

    public function store(Request $request, ProductVariant $variant): JsonResponse
    {
        $variant->loadMissing('product.seller');

        abort_unless($variant->product->isVisibleToBuyers(), HttpResponse::HTTP_NOT_FOUND);

        $subscription = $this->subscriptions->subscribe($variant, $this->currentUser($request));

        return ApiResponse::created([
            'variant_id' => $variant->getKey(),
            'subscribed' => true,
            'created_at' => $subscription->created_at?->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, ProductVariant $variant): JsonResponse
    {
        $this->subscriptions->unsubscribe($variant, $this->currentUser($request));

        return ApiResponse::ok(['variant_id' => $variant->getKey(), 'subscribed' => false]);
    }
}
