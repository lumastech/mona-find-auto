<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Http\Resources\CartResource;
use App\Modules\Shopping\Http\Resources\WishlistItemResource;
use App\Modules\Shopping\Models\WishlistItem;
use App\Modules\Shopping\Services\CartService;
use App\Modules\Shopping\Services\WishlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The wishlist, for the mobile app.
 *
 * The same service and the same resource as the web page, so the price-drop
 * and stock-change indicators cannot come out different on a phone. Bound by
 * listing id rather than slug — the app holds ids.
 */
class WishlistController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly WishlistService $wishlist,
        private readonly CartService $cart,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->wishlist->forUser($this->currentUser($request));

        return ApiResponse::ok(
            $items->map(fn (WishlistItem $item): array => WishlistItemResource::make($item)->resolve($request))->all(),
            ['count' => $items->count()],
        );
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $item = $this->wishlist->add($this->currentUser($request), $product);

        $item->load(['product.seller.city', 'product.variants', 'product.media', 'product.category']);

        return ApiResponse::created(WishlistItemResource::make($item)->resolve($request));
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->wishlist->remove($this->currentUser($request), $product);

        return ApiResponse::noContent();
    }

    /**
     * Move a saved listing into the cart, and hand back the cart it landed in
     * — the app needs the new totals anyway.
     */
    public function moveToCart(Request $request, Product $product): JsonResponse
    {
        $user = $this->currentUser($request);

        try {
            $this->wishlist->moveToCart($user, $product);
        } catch (ListingNotPurchasable $exception) {
            return ApiResponse::error(
                'listing_not_purchasable',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        return ApiResponse::ok(CartResource::make($this->cart->view($user))->resolve($request));
    }
}
