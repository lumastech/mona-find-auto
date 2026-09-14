<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Ratings\Enums\ReportReason;
use App\Modules\Ratings\Services\RatingFeed;
use App\Modules\Sellers\Http\Resources\SellerProfileResource;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerPolicyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A seller's public page.
 *
 * Guests reach this like any other storefront page — it is server-rendered so
 * search engines index it — and the contact block is the one part of it they
 * do not get: labels and a masked shape, with a prompt to log in.
 */
class SellerProfileController extends Controller
{
    public function __construct(
        private readonly SellerPolicyService $policies,
        private readonly RatingFeed $reviews,
    ) {}

    public function show(Request $request, Seller $seller): Response
    {
        /*
         * A draft or rejected application is not a shop, and a suspended one
         * has been taken down. 404 rather than 403: a buyer has no business
         * knowing the difference.
         */
        abort_unless($seller->verification_status->isPubliclyVisible(), HttpResponse::HTTP_NOT_FOUND);

        $seller->load(['province', 'city', 'currentPolicies']);

        return Inertia::render('storefront/sellers/Show', [
            'seller' => SellerProfileResource::make($seller)->resolve($request),
            'platformMinimumRefund' => $this->policies->platformMinimumRefund(),
            'mapsApiKey' => config('services.google_maps.browser_key'),
            'reviews' => $this->reviews->publicFor($seller, $request),
            'reportReasons' => ReportReason::options(),
        ]);
    }
}
