<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Http\Requests\Storefront\CartItemRequest;
use App\Modules\Shopping\Http\Requests\Storefront\CartQuantityRequest;
use App\Modules\Shopping\Http\Resources\CartResource;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The cart.
 *
 * Reading it re-prices it. CartService::view() checks every line against its
 * listing on every request, which is why there is no "refresh" action here
 * and no job that keeps carts warm: the page a buyer is looking at is the
 * only cart that matters, and it is correct because it was just rebuilt.
 */
class CartController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly CartService $cart) {}

    public function index(Request $request): Response
    {
        $view = $this->cart->view($this->currentUser($request));

        return Inertia::render('storefront/Cart', [
            'cart' => CartResource::make($view)->resolve($request),
        ]);
    }

    public function store(CartItemRequest $request): RedirectResponse
    {
        $variant = ProductVariant::query()->whereKey($request->validated('variant_id'))->firstOrFail();

        try {
            $this->cart->add($request->user(), $variant, $request->quantity());
        } catch (ListingNotPurchasable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added to your cart.')]);

        return back();
    }

    public function update(CartQuantityRequest $request, CartItem $item): RedirectResponse
    {
        $this->authoriseOwnership($request, $item);

        $this->cart->updateQuantity($item, $request->quantity());

        return to_route('cart.index');
    }

    public function destroy(Request $request, CartItem $item): RedirectResponse
    {
        $this->authoriseOwnership($request, $item);

        $this->cart->remove($item);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed from your cart.')]);

        return to_route('cart.index');
    }

    public function clear(Request $request): RedirectResponse
    {
        $this->cart->clear($this->currentUser($request));

        return to_route('cart.index');
    }

    /**
     * A line belongs to one cart and a cart to one buyer.
     *
     * 404 rather than 403: somebody guessing at line ids has no business
     * learning which ones exist.
     */
    private function authoriseOwnership(Request $request, CartItem $item): void
    {
        $cart = $this->cart->for($this->currentUser($request));

        abort_unless($item->cart_id === $cart->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
