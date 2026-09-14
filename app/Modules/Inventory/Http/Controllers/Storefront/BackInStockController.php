<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\BackInStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * "Tell me when this is back."
 *
 * Signed-in buyers only, and not because of any policy about guests: there is
 * nowhere to send a notification to somebody the platform cannot identify.
 * The listing page shows a guest the button and sends them to log in, which
 * is the same shape as the blurred seller contact details beside it.
 */
class BackInStockController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly BackInStockService $subscriptions) {}

    public function store(Request $request, ProductVariant $variant): RedirectResponse
    {
        $this->guardVisible($variant);

        $this->subscriptions->subscribe($variant, $this->currentUser($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('We will let you know as soon as this is back in stock.'),
        ]);

        return back();
    }

    public function destroy(Request $request, ProductVariant $variant): RedirectResponse
    {
        $this->subscriptions->unsubscribe($variant, $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You will no longer be notified.')]);

        return back();
    }

    /**
     * A buyer cannot subscribe to a listing they are not allowed to see —
     * including one hidden for going unconfirmed. 404 rather than 403: they
     * have no business knowing the difference.
     */
    private function guardVisible(ProductVariant $variant): void
    {
        $variant->loadMissing('product.seller');

        abort_unless($variant->product->isVisibleToBuyers(), HttpResponse::HTTP_NOT_FOUND);
    }
}
