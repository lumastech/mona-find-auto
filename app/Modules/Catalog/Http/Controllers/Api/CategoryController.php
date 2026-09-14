<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Http\Resources\ProductCardResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CategoryTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browsing the parts catalogue by category, for the mobile app.
 *
 * The counterpart of the storefront's /parts pages. A buyer who does not know
 * what the part is called browses to it, and search does not help them —
 * which makes this the other half of finding something, not a nicety.
 *
 * Same query, same filters and the same card resource as the web page, so a
 * result reached in the app matches one reached in a browser.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly CategoryTree $categories) {}

    /**
     * The whole tree, nested, plus the makes worth putting on a home screen.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::ok([
            'tree' => $this->categories->nested(activeOnly: true),
            'popular_makes' => Make::query()
                ->selectable()
                ->where('is_popular', true)
                ->get(['id', 'name', 'slug']),
        ]);
    }

    /**
     * One category and the listings in it, including everything in its
     * subtree — browsing "Engine" returns injectors.
     *
     * Bound by slug, which is what the tree hands the client.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $category = Category::query()->where('slug', $slug)->firstOrFail();

        abort_unless($category->is_active, Response::HTTP_NOT_FOUND);

        $filters = $request->validate([
            'condition' => ['nullable', 'string'],
            'inspected' => ['nullable', 'boolean'],
            'make_id' => ['nullable', 'integer'],
            'delivery' => ['nullable', 'boolean'],
        ]);

        $listings = Product::query()
            ->published()
            ->inCategory($category)
            ->withCardRelations()
            ->when(
                Condition::tryFrom($filters['condition'] ?? ''),
                fn ($query, Condition $condition) => $query->where('condition', $condition),
            )
            ->when(
                $filters['inspected'] ?? null,
                fn ($query) => $query->where('inspection_status', InspectionStatus::Inspected),
            )
            ->when(
                $filters['make_id'] ?? null,
                fn ($query, int $makeId) => $query->where('make_id', $makeId),
            )
            ->when(
                $filters['delivery'] ?? null,
                fn ($query) => $query->where('delivery_available', true),
            )
            ->latest('published_at')
            ->paginate(24)
            ->withQueryString();

        return ApiResponse::paginated(
            $listings->through(fn (Product $product): array => ProductCardResource::make($product)->resolve($request)),
            [
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                ],
                'breadcrumb' => $this->categories->breadcrumbFor($category),
                'children' => $category->children()->where('is_active', true)->get(['id', 'name', 'slug']),
                'filters' => $filters,
                'conditions' => Condition::options(),
            ],
        );
    }
}
