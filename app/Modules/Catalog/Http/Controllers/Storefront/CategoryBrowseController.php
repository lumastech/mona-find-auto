<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Http\Resources\ProductCardResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CategoryTree;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Browsing the catalogue by category.
 *
 * Server-rendered like the listing page, and for the same reason: category
 * pages are what a search engine crawls to find the listings under them.
 *
 * Browsing a heading returns everything beneath it — "Engine" has to include
 * injectors — which is what the materialised path on Category is for.
 *
 * Ranking here is deliberately simple: newest first. The quality-scored
 * ranking the brief describes belongs to the Search module, and putting half
 * of it here would leave two rankings to keep in step.
 */
class CategoryBrowseController extends Controller
{
    public function __construct(private readonly CategoryTree $categories) {}

    /**
     * The top of the catalogue: the root categories and what is under them.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('storefront/categories/Index', [
            'tree' => $this->categories->nested(activeOnly: true),
            'popularMakes' => Make::query()
                ->selectable()
                ->where('is_popular', true)
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function show(Request $request, Category $category): Response
    {
        abort_unless($category->is_active, HttpResponse::HTTP_NOT_FOUND);

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
            ->withQueryString()
            ->through(fn (Product $product): array => ProductCardResource::make($product)->resolve($request));

        return Inertia::render('storefront/categories/Show', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
            ],
            'breadcrumb' => $this->categories->breadcrumbFor($category),
            'children' => $category->children()->where('is_active', true)->get(['id', 'name', 'slug']),
            'listings' => $listings,
            'filters' => $filters,
            'conditions' => Condition::options(),
            'makes' => Make::query()->selectable()->where('is_popular', true)->get(['id', 'name']),
        ]);
    }
}
