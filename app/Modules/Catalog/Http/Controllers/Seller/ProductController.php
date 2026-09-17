<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\BodyType;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\DriveType;
use App\Modules\Catalog\Enums\FuelType;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Catalog\Enums\Transmission;
use App\Modules\Catalog\Exceptions\ConditionNotAllowed;
use App\Modules\Catalog\Http\Requests\Seller\ProductRequest;
use App\Modules\Catalog\Http\Resources\SellerProductResource;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\VehicleModel;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\ListingModerationService;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A seller's own listings.
 *
 * The form here mirrors the server rules field for field, so a seller on a
 * slow connection is told what is wrong before the request goes anywhere. The
 * two things the form cannot decide are absent from it by design: the
 * condition is forced for a car breaker, and the inspection badge is staff's.
 */
class ProductController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(
        private readonly ProductService $products,
        private readonly ListingModerationService $moderation,
        private readonly CategoryTree $categories,
    ) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $listings = Product::query()
            ->where('seller_id', $seller->getKey())
            ->withPortalRelations()
            ->when(
                ListingStatus::tryFrom($filters['status'] ?? ''),
                fn ($query, ListingStatus $status) => $query->where('status', $status),
            )
            ->search($filters['search'] ?? null)
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $product): array => SellerProductResource::make($product)->resolve($request));

        return Inertia::render('seller/listings/Index', [
            'listings' => $listings,
            'filters' => $filters,
            'statuses' => ListingStatus::options(),
            'counts' => $this->countsByStatus($seller),
            'canList' => $this->moderation->sellerMayList($seller),
        ]);
    }

    public function create(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        return Inertia::render('seller/listings/Edit', [
            'listing' => null,
            ...$this->formOptions($seller),
        ]);
    }

    /**
     * @throws ValidationException when the seller's type forbids the condition
     */
    public function store(ProductRequest $request): RedirectResponse
    {
        $seller = $this->currentSeller($request);

        try {
            $product = $this->products->create(
                $seller,
                $request->listingAttributes(),
                $request->variantRows(),
            );
        } catch (ConditionNotAllowed $exception) {
            throw ValidationException::withMessages(['condition' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Draft saved. Add photos, then send it for review.'),
        ]);

        return to_route('seller.listings.edit', $product);
    }

    public function edit(Request $request, Product $product): Response
    {
        $seller = $this->currentSeller($request);
        Gate::authorize('view', $product);

        $product->load(['seller', 'variants', 'category', 'media', 'reviewEvents.actor']);

        return Inertia::render('seller/listings/Edit', [
            'listing' => SellerProductResource::make($product)->resolve($request),
            'history' => $product->reviewEvents
                ->map(static fn ($event): array => [
                    'id' => $event->id,
                    'summary' => $event->summary(),
                    'reason' => $event->reason,
                    'field_reasons' => $event->field_reasons ?? [],
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->all(),
            'duplicateWarning' => $this->duplicateWarning($seller, $product),
            ...$this->formOptions($seller),
        ]);
    }

    /**
     * @throws ValidationException when the seller's type forbids the condition
     */
    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        try {
            $this->products->update(
                $product,
                $request->listingAttributes(),
                $request->variantRows(),
            );
        } catch (ConditionNotAllowed $exception) {
            throw ValidationException::withMessages(['condition' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing saved.')]);

        return back();
    }

    /**
     * Retire a listing. Order history still points at it, so it is archived
     * rather than deleted.
     */
    public function destroy(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('archive', $product);

        $this->moderation->archive($product, $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing archived.')]);

        return to_route('seller.listings.index');
    }

    /**
     * Everything the listing form needs to render its dropdowns.
     *
     * The condition options are filtered by the seller's type: a car breaker
     * is shown one option and told why, rather than being offered three and
     * refused two of them on submit.
     *
     * @return array<string, mixed>
     */
    private function formOptions(Seller $seller): array
    {
        return [
            'categories' => $this->categories->nested(activeOnly: true),
            'makes' => Make::query()->selectable()->get(['id', 'name', 'is_popular']),
            'vehicleModels' => VehicleModel::query()
                ->selectable()
                ->get(['id', 'make_id', 'name', 'production_start_year', 'production_end_year']),
            'conditions' => Condition::optionsFor($seller->type),
            'conditionLocked' => Condition::forcedFor($seller->type)?->value,
            'sourcingOptions' => PartSourcing::options(),
            'fuelTypes' => FuelType::options(),
            'transmissions' => Transmission::options(),
            'driveTypes' => DriveType::options(),
            'bodyTypes' => BodyType::options(),
            'limits' => [
                'min_photos' => Product::MIN_PHOTOS,
                'max_photos' => Product::MAX_PHOTOS,
                'max_video_seconds' => Product::MAX_VIDEO_SECONDS,
            ],
        ];
    }

    /**
     * Warn — never block — when this seller already lists the same part number.
     *
     * Duplicates are usually a genuine second unit rather than a mistake, so
     * this is a nudge beside the field. Blocking it would stop a breaker
     * listing the two alternators they actually have.
     *
     * @return array{part_number: string, count: int}|null
     */
    private function duplicateWarning(Seller $seller, Product $product): ?array
    {
        if (blank($product->part_number)) {
            return null;
        }

        $count = Product::query()
            ->where('seller_id', $seller->getKey())
            ->where('part_number', $product->part_number)
            ->whereKeyNot($product->getKey())
            ->whereNot('status', ListingStatus::Archived)
            ->count();

        return $count === 0 ? null : ['part_number' => $product->part_number, 'count' => $count];
    }

    /**
     * The tab counts above the list.
     *
     * @return array<string, int>
     */
    private function countsByStatus(Seller $seller): array
    {
        $counts = Product::query()
            ->where('seller_id', $seller->getKey())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return array_merge(
            array_fill_keys(ListingStatus::values(), 0),
            array_map('intval', $counts),
        );
    }
}
