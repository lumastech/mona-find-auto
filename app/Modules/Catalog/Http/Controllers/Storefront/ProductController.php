<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Resources\ProductCardResource;
use App\Modules\Catalog\Http\Resources\ProductDetailResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Ratings\Enums\ReportReason;
use App\Modules\Ratings\Services\RatingFeed;
use App\Modules\Sellers\Services\SellerPolicyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A listing's public page.
 *
 * Server-rendered, because this is where most buyers arrive from a search
 * engine. Everything on it is public except the seller's contact block, which
 * a guest gets as labels and a masked shape — the same rule, through the same
 * code, as the seller's own page.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly CategoryTree $categories,
        private readonly SellerPolicyService $policies,
        private readonly RatingFeed $reviews,
    ) {}

    public function show(Request $request, Product $product): Response
    {
        /*
         * A draft, a listing in the queue, or one whose shop has been
         * suspended is not a listing a buyer may see. 404 rather than 403: a
         * buyer has no business knowing the difference.
         */
        abort_unless($product->isVisibleToBuyers(), HttpResponse::HTTP_NOT_FOUND);

        $product->load([
            'seller.province',
            'seller.city',
            'seller.currentPolicies',
            'category',
            'make',
            'vehicleModel',
            'variants',
            'media',
        ]);

        return Inertia::render('storefront/listings/Show', [
            'listing' => ProductDetailResource::make($product)->resolve($request),
            'breadcrumb' => $this->categories->breadcrumbFor($product->category),
            'related' => $this->related($request, $product),
            'platformMinimumRefund' => $this->policies->platformMinimumRefund(),
            /*
             * Reviews are about the SHOP, not the part. A buyer deciding
             * whether to send money to a yard in Kitwe is judging the yard —
             * and a per-listing review list on a catalogue where most parts
             * sell once would be empty on almost every page.
             */
            'reviews' => $this->reviews->publicFor($product->seller, $request),
            'reportReasons' => ReportReason::options(),
        ]);
    }

    /**
     * A few more listings from the same category, so a buyer whose part is
     * out of stock has somewhere to go rather than back to search.
     *
     * @return array<int, array<string, mixed>>
     */
    private function related(Request $request, Product $product): array
    {
        return Product::query()
            ->published()
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->getKey())
            ->withCardRelations()
            ->latest('published_at')
            ->limit(4)
            ->get()
            ->map(fn (Product $related): array => ProductCardResource::make($related)->resolve($request))
            ->all();
    }
}
