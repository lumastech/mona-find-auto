<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Enums\QuotationStatus;
use App\Modules\Shopping\Exceptions\InvalidQuotationTransition;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Exceptions\QuotationNotAcceptable;
use App\Modules\Shopping\Http\Requests\Seller\QuotationDeclineRequest;
use App\Modules\Shopping\Http\Requests\Seller\QuotationResponseRequest;
use App\Modules\Shopping\Http\Requests\Storefront\QuotationRequestRequest;
use App\Modules\Shopping\Http\Resources\CartResource;
use App\Modules\Shopping\Http\Resources\QuotationResource;
use App\Modules\Shopping\Models\Quotation;
use App\Modules\Shopping\Services\CartService;
use App\Modules\Shopping\Services\QuotationService;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Quotations, for the mobile app — both sides of them.
 *
 * One controller because it is one resource: a buyer lists their own requests
 * and a seller lists the ones addressed to them, and which of the two is
 * asking decides what comes back. The authority to answer is the policy's
 * question, exactly as on the web, so the "only the addressed seller may
 * quote" rule has one implementation rather than two.
 */
class QuotationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly QuotationService $quotations,
        private readonly CartService $cart,
    ) {}

    /**
     * The caller's own requests — or, with `?role=seller`, the ones addressed
     * to their shop.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $filters = $request->validate([
            'role' => ['nullable', 'in:buyer,seller'],
            'status' => ['nullable', 'string'],
        ]);

        /* Null unless the caller asked for their shop's side and actually has one. */
        $shop = ($filters['role'] ?? 'buyer') === 'seller' ? $user->seller : null;

        if (($filters['role'] ?? null) === 'seller' && $shop === null) {
            return ApiResponse::error(
                'not_a_seller',
                'This account does not run a shop on MonaFind.',
                status: HttpResponse::HTTP_FORBIDDEN,
            );
        }

        $status = QuotationStatus::tryFrom($filters['status'] ?? '');

        $quotations = Quotation::query()
            ->when(
                $shop,
                fn ($query, Seller $shop) => $query->forSeller($shop),
                fn ($query) => $query->forBuyer($user),
            )
            ->with(['seller', 'buyer', 'product.media', 'variant'])
            ->when($status, fn ($query, QuotationStatus $status) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Quotation $quotation): array => QuotationResource::make($quotation)->resolve($request));

        return ApiResponse::paginated($quotations);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        Gate::authorize('view', $quotation);

        $quotation->load(['seller', 'buyer', 'product.media', 'variant']);

        return ApiResponse::ok(QuotationResource::make($quotation)->resolve($request));
    }

    public function store(QuotationRequestRequest $request): JsonResponse
    {
        $variant = ProductVariant::query()->whereKey($request->validated('variant_id'))->firstOrFail();

        try {
            $quotation = $this->quotations->request(
                buyer: $request->user(),
                variant: $variant,
                quantity: (int) $request->validated('quantity'),
                message: $request->validated('message'),
            );
        } catch (ListingNotPurchasable $exception) {
            return ApiResponse::error(
                'listing_not_purchasable',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        $quotation->load(['seller', 'product.media', 'variant']);

        return ApiResponse::created(QuotationResource::make($quotation)->resolve($request));
    }

    /**
     * The addressed shop answers with a price.
     */
    public function respond(QuotationResponseRequest $request, Quotation $quotation): JsonResponse
    {
        Gate::authorize('quote', $quotation);

        try {
            $this->quotations->quote(
                quotation: $quotation,
                unitPrice: Money::ofKwacha((string) $request->validated('unit_price')),
                validUntil: $request->date('valid_until') ?? now(),
                deliveryNote: $request->validated('delivery_note'),
            );
        } catch (InvalidQuotationTransition $exception) {
            return ApiResponse::error(
                'invalid_quotation_transition',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        $quotation->load(['seller', 'buyer', 'product.media', 'variant']);

        return ApiResponse::ok(QuotationResource::make($quotation)->resolve($request));
    }

    public function decline(QuotationDeclineRequest $request, Quotation $quotation): JsonResponse
    {
        Gate::authorize('decline', $quotation);

        try {
            $this->quotations->decline($quotation, $request->validated('reason'));
        } catch (InvalidQuotationTransition $exception) {
            return ApiResponse::error(
                'invalid_quotation_transition',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        $quotation->load(['seller', 'buyer', 'product.media', 'variant']);

        return ApiResponse::ok(QuotationResource::make($quotation)->resolve($request));
    }

    /**
     * The buyer takes the price. Answers with the cart it landed in.
     */
    public function accept(Request $request, Quotation $quotation): JsonResponse
    {
        Gate::authorize('accept', $quotation);

        try {
            $this->quotations->accept($quotation);
        } catch (QuotationNotAcceptable $exception) {
            return ApiResponse::error(
                'quotation_not_acceptable',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        return ApiResponse::ok(
            CartResource::make($this->cart->view($this->currentUser($request)))->resolve($request),
        );
    }
}
