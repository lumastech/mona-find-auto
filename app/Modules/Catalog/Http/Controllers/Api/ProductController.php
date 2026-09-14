<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Http\Resources\ProductCardResource;
use App\Modules\Catalog\Http\Resources\ProductDetailResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Published listings, for the mobile app.
 *
 * Public, exactly as the storefront is: guests may browse everything. Only
 * published listings from shops that are still up are visible, and the
 * seller's contact details follow the same blur rule as the web page, applied
 * by the one resource both surfaces answer through.
 *
 * Bound by id rather than slug — the mobile app holds ids.
 */
class ProductController extends Controller
{
    /**
     * Browse listings.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'make_id' => ['nullable', 'integer'],
            'vehicle_model_id' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string'],
            'inspected' => ['nullable', 'boolean'],
            'seller_id' => ['nullable', 'integer'],
            'delivery' => ['nullable', 'boolean'],
        ]);

        $category = isset($filters['category_id'])
            ? Category::query()->whereKey($filters['category_id'])->first()
            : null;

        $listings = Product::query()
            ->published()
            ->withCardRelations()
            ->search($filters['search'] ?? null)
            /* Browsing a heading includes everything beneath it. */
            ->when($category, fn ($query, Category $category) => $query->inCategory($category))
            ->when($filters['make_id'] ?? null, fn ($query, int $id) => $query->where('make_id', $id))
            ->when($filters['vehicle_model_id'] ?? null, fn ($query, int $id) => $query->where('vehicle_model_id', $id))
            ->when($filters['seller_id'] ?? null, fn ($query, int $id) => $query->where('seller_id', $id))
            ->when(
                Condition::tryFrom($filters['condition'] ?? ''),
                fn ($query, Condition $condition) => $query->where('condition', $condition),
            )
            ->when(
                $filters['inspected'] ?? null,
                fn ($query) => $query->where('inspection_status', InspectionStatus::Inspected),
            )
            ->when($filters['delivery'] ?? null, fn ($query) => $query->where('delivery_available', true))
            ->latest('published_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Product $product): array => ProductCardResource::make($product)->resolve($request));

        return ApiResponse::paginated($listings);
    }

    /**
     * One listing in full.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
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

        return ApiResponse::ok(ProductDetailResource::make($product)->resolve($request));
    }
}
