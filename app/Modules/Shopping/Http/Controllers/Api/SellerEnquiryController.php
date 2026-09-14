<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Contracts\SellerEnquiryChannel;
use App\Modules\Shopping\Http\Requests\Storefront\SellerEnquiryRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * "Contact seller", for the mobile app.
 *
 * The web has had this button since the shopfront did; the API had not, which
 * left an app able to show a buyer a listing and no way to ask about it —
 * the single most common thing a buyer wants to do short of buying.
 *
 * Goes through the same `SellerEnquiryChannel` contract as the web form, so
 * an enquiry raised in the app lands in the same thread the seller reads on
 * the web.
 */
class SellerEnquiryController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly SellerEnquiryChannel $enquiries) {}

    public function store(SellerEnquiryRequest $request, Seller $seller): JsonResponse
    {
        /* A shop under review or suspended is not one a buyer can write to. */
        abort_unless($seller->verification_status->isPubliclyVisible(), HttpResponse::HTTP_NOT_FOUND);

        $product = $request->validated('product_id') === null
            ? null
            : Product::query()->whereKey($request->validated('product_id'))->first();

        $this->enquiries->send(
            buyer: $this->currentUser($request),
            seller: $seller,
            message: (string) $request->validated('message'),
            product: $product,
        );

        return ApiResponse::created([
            'sent' => true,
            'seller' => ['id' => $seller->id, 'business_name' => $seller->business_name],
        ]);
    }
}
