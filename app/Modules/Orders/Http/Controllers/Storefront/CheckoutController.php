<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Exceptions\CheckoutUnavailable;
use App\Modules\Orders\Http\Requests\Storefront\PlaceOrderRequest;
use App\Modules\Orders\Http\Resources\CheckoutResource;
use App\Modules\Orders\Services\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkout: the cart, per shop, with each shop's terms in front of the buyer.
 *
 * The page is rebuilt from the cart on every load, which means it is rebuilt
 * from re-priced lines — a buyer who left this tab open for two days sees
 * today's prices here rather than at the payment step. If anything in the
 * cart has become unbuyable they are sent back to the cart, where the page is
 * already built to explain what went.
 *
 * Placing an order does not take any money. It writes the orders and the
 * acceptance record and hands the buyer to payment, which is a separate
 * module and a separate step: an order that exists unpaid can be abandoned
 * harmlessly, whereas money taken against orders that were never written is a
 * refund and an apology.
 */
class CheckoutController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly CheckoutService $checkout) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $view = $this->checkout->view($this->currentUser($request));

        if ($view->isEmpty()) {
            return to_route('cart.index');
        }

        if ($view->blocksCheckout()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Something in your cart is no longer available.'),
            ]);

            return to_route('cart.index');
        }

        return Inertia::render('storefront/Checkout', [
            'checkout' => CheckoutResource::make($view)->resolve($request),
            'mapsApiKey' => config('services.google_maps.browser_key'),
        ]);
    }

    public function store(PlaceOrderRequest $request): RedirectResponse
    {
        try {
            $group = $this->checkout->place(
                $this->currentUser($request),
                $request->selections(),
                $request->paymentMethod(),
                $request,
            );
        } catch (CheckoutUnavailable $exception) {
            /*
             * Everything CheckoutUnavailable carries is something the buyer
             * can act on — a shop that stopped delivering, a policy
             * republished while they read it — so it goes back to the page as
             * a sentence rather than becoming a 500.
             */
            return back()->withErrors(['checkout' => $exception->getMessage()]);
        }

        /*
         * Straight to the first order rather than to a list. The buyer's next
         * move is to pay, and place() cannot return a group with no orders in
         * it — an empty cart is refused before anything is written.
         */
        return to_route('orders.show', $group->orders->firstOrFail()->number)
            ->with('toast', [
                'type' => 'success',
                'message' => __('Your order is placed. Pay to send it to the seller.'),
            ]);
    }
}
