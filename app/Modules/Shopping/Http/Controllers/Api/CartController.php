<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Http\Requests\Storefront\CartItemRequest;
use App\Modules\Shopping\Http\Requests\Storefront\CartQuantityRequest;
use App\Modules\Shopping\Http\Resources\CartResource;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The cart, for the mobile app.
 *
 * Every action answers with the whole re-validated cart rather than the line
 * it touched. A phone on a Zambian mobile connection should not have to make
 * a second request to find out that adding one part clamped another line or
 * dropped a listing that had been taken down.
 */
class CartController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly CartService $cart) {}

    public function index(Request $request): JsonResponse
    {
        return $this->cartResponse($request);
    }

    public function store(CartItemRequest $request): JsonResponse
    {
        $variant = ProductVariant::query()->whereKey($request->validated('variant_id'))->firstOrFail();

        try {
            $this->cart->add($request->user(), $variant, $request->quantity());
        } catch (ListingNotPurchasable $exception) {
            return ApiResponse::error(
                'listing_not_purchasable',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        return $this->cartResponse($request, HttpResponse::HTTP_CREATED);
    }

    public function update(CartQuantityRequest $request, CartItem $item): JsonResponse
    {
        $this->authoriseOwnership($request, $item);

        $this->cart->updateQuantity($item, $request->quantity());

        return $this->cartResponse($request);
    }

    public function destroy(Request $request, CartItem $item): JsonResponse
    {
        $this->authoriseOwnership($request, $item);

        $this->cart->remove($item);

        return $this->cartResponse($request);
    }

    /**
     * Empty the whole cart.
     *
     * The web has had this since the cart screen did; the API had not, which
     * left an app either removing lines one at a time or offering no way to
     * start over. Answers with the now-empty cart rather than 204, so a
     * client re-renders from the response instead of guessing.
     */
    public function clear(Request $request): JsonResponse
    {
        $this->cart->clear($this->currentUser($request));

        return $this->cartResponse($request);
    }

    private function cartResponse(Request $request, int $status = HttpResponse::HTTP_OK): JsonResponse
    {
        $view = $this->cart->view($this->currentUser($request));

        return ApiResponse::ok(CartResource::make($view)->resolve($request), status: $status);
    }

    /**
     * 404 rather than 403: somebody guessing at line ids has no business
     * learning which ones exist.
     */
    private function authoriseOwnership(Request $request, CartItem $item): void
    {
        $cart = $this->cart->for($this->currentUser($request));

        abort_unless($item->cart_id === $cart->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
