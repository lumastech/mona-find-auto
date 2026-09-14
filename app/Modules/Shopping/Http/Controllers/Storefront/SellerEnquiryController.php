<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Contracts\SellerEnquiryChannel;
use App\Modules\Shopping\Http\Requests\Storefront\SellerEnquiryRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * "Contact seller."
 *
 * Signed-in buyers only, for the same reason the back-in-stock button is:
 * there is nowhere to put a reply to somebody the platform cannot identify.
 * The blurred contact block beside it is the other half of the same rule —
 * a guest sees that a phone number exists and is asked to log in to read it.
 *
 * The message goes through SellerEnquiryChannel rather than to a table, so
 * when Messaging arrives this controller does not change.
 */
class SellerEnquiryController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly SellerEnquiryChannel $enquiries) {}

    public function store(SellerEnquiryRequest $request, Seller $seller): RedirectResponse
    {
        /* A shop under review or suspended is not one a buyer can write to. */
        abort_unless($seller->verification_status->isPubliclyVisible(), HttpResponse::HTTP_NOT_FOUND);

        $product = $request->validated('product_id') === null
            ? null
            : Product::query()->whereKey($request->validated('product_id'))->first();

        $this->enquiries->send(
            buyer: $request->user(),
            seller: $seller,
            message: (string) $request->validated('message'),
            product: $product,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your message has been sent to :seller.', ['seller' => $seller->business_name]),
        ]);

        return back();
    }
}
