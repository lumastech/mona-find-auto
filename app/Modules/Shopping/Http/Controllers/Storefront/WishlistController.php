<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Http\Resources\WishlistItemResource;
use App\Modules\Shopping\Models\WishlistItem;
use App\Modules\Shopping\Services\WishlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The wishlist: what a buyer saved, and what has happened to it since.
 *
 * Behind auth, and that is what the heart on a guest's screen is for. A guest
 * pressing it is sent to log in and comes back to the listing they were
 * looking at — Laravel's intended-url redirect does that for free, which is
 * why the heart is a real form post to a guarded route rather than a
 * client-side check that hides the button.
 */
class WishlistController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly WishlistService $wishlist) {}

    public function index(Request $request): Response
    {
        $items = $this->wishlist->forUser($this->currentUser($request));

        return Inertia::render('storefront/Wishlist', [
            'items' => $items
                ->map(fn (WishlistItem $item): array => WishlistItemResource::make($item)->resolve($request))
                ->all(),
        ]);
    }

    /**
     * Save a listing. Idempotent: a second press is not a second save, and
     * must not reset the price the drop is measured against.
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        $this->wishlist->add($this->currentUser($request), $product);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved to your wishlist.')]);

        return back();
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->wishlist->remove($this->currentUser($request), $product);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed from your wishlist.')]);

        return back();
    }

    /**
     * Into the cart, and off the list.
     *
     * A listing whose stock went while it sat on the wishlist cannot move,
     * and the buyer is told which of the two reasons it was rather than being
     * left with a button that did nothing.
     */
    public function moveToCart(Request $request, Product $product): RedirectResponse
    {
        try {
            $this->wishlist->moveToCart($this->currentUser($request), $product);
        } catch (ListingNotPurchasable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Moved to your cart.')]);

        return to_route('cart.index');
    }
}
