<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Http\Requests\Admin\ModerationDecisionRequest;
use App\Modules\Catalog\Http\Resources\ProductDetailResource;
use App\Modules\Catalog\Http\Resources\SellerProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\ListingModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The listing moderation queue.
 *
 * A moderator reads one listing at a time with the photos beside the fields
 * they describe, and turns it down field by field. That shape is the point:
 * a seller told "rejected" resubmits the same listing, and a seller told
 * "photos: too dark to see the part" fixes the photos.
 *
 * Nothing here edits a listing's content. A moderator who could rewrite a
 * listing and then approve it is not reviewing anything.
 */
class ListingModerationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly ListingModerationService $moderation,
        private readonly CategoryTree $categories,
    ) {}

    /**
     * The queue, oldest first — a queue, not a list.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'condition' => ['nullable', 'string'],
            'inspection_status' => ['nullable', 'string'],
        ]);

        $status = ListingStatus::tryFrom($filters['status'] ?? '');

        $listings = Product::query()
            ->withPortalRelations()
            ->when(
                $status === null,
                fn ($query) => $query->awaitingModeration(),
                fn ($query) => $query->where('status', $status)->latest('submitted_at'),
            )
            ->when(
                Condition::tryFrom($filters['condition'] ?? ''),
                fn ($query, Condition $condition) => $query->where('condition', $condition),
            )
            ->when(
                InspectionStatus::tryFrom($filters['inspection_status'] ?? ''),
                fn ($query, InspectionStatus $inspection) => $query->where('inspection_status', $inspection),
            )
            ->search($filters['search'] ?? null)
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                ...SellerProductResource::make($product)->resolve($request),
                'seller' => [
                    'id' => $product->seller->id,
                    'slug' => $product->seller->slug,
                    'business_name' => $product->seller->business_name,
                    'type_label' => $product->seller->type->label(),
                    'verified' => $product->seller->isVerified(),
                ],
            ]);

        return Inertia::render('admin/listings/Index', [
            'listings' => $listings,
            'filters' => $filters,
            'statuses' => ListingStatus::options(),
            'conditions' => Condition::options(),
            'inspectionStatuses' => InspectionStatus::options(),
            'queueSize' => Product::query()->awaitingModeration()->count(),
        ]);
    }

    /**
     * One listing, with everything a decision needs on the same screen.
     */
    public function show(Request $request, Product $product): Response
    {
        Gate::authorize('view', $product);

        $product->load(['seller.province', 'seller.city', 'category', 'make', 'vehicleModel', 'variants', 'media', 'reviewEvents.actor']);

        return Inertia::render('admin/listings/Show', [
            'listing' => [
                ...SellerProductResource::make($product)->resolve($request),
                /* The buyer's view of the same listing, so a moderator reviews what a buyer will read. */
                'storefront' => ProductDetailResource::make($product)->resolve($request),
            ],
            'seller' => [
                'id' => $product->seller->id,
                'slug' => $product->seller->slug,
                'business_name' => $product->seller->business_name,
                'type_label' => $product->seller->type->label(),
                'verified' => $product->seller->isVerified(),
                'verification_label' => $product->seller->verification_status->label(),
                'sells_breaker_stock' => $product->seller->type->sellsBreakerStock(),
                'location' => $product->seller->singleLine(),
            ],
            'breadcrumb' => $this->categories->breadcrumbFor($product->category),
            'history' => $product->reviewEvents
                ->map(static fn ($event): array => [
                    'id' => $event->id,
                    'summary' => $event->summary(),
                    'reason' => $event->reason,
                    'field_reasons' => $event->field_reasons ?? [],
                    'note' => $event->note,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->all(),
            'rejectableFields' => ModerationDecisionRequest::REJECTABLE_FIELDS,
            'inspectionStatuses' => InspectionStatus::options(),
            'canModerate' => $request->user()?->can('moderate', $product) ?? false,
            'canInspect' => $request->user()?->can('inspect', $product) ?? false,
        ]);
    }

    public function publish(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('moderate', $product);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->moderation->publish($product, $this->currentUser($request), $validated['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing published.')]);

        return to_route('admin.listings.index');
    }

    public function reject(ModerationDecisionRequest $request, Product $product): RedirectResponse
    {
        $this->moderation->reject(
            $product,
            $this->currentUser($request),
            $request->string('reason')->toString(),
            $request->fieldReasons(),
            $request->string('note')->toString() ?: null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Listing rejected. The seller can see your reasons and send it again.'),
        ]);

        return to_route('admin.listings.index');
    }

    /**
     * Pull a live listing down without retiring it.
     */
    public function unpublish(ModerationDecisionRequest $request, Product $product): RedirectResponse
    {
        $this->moderation->unpublish(
            $product,
            $this->currentUser($request),
            $request->string('reason')->toString(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing taken down.')]);

        return back();
    }
}
