<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Exceptions\QuotationNotAcceptable;
use App\Modules\Shopping\Http\Requests\Storefront\QuotationRequestRequest;
use App\Modules\Shopping\Http\Resources\QuotationResource;
use App\Modules\Shopping\Models\Quotation;
use App\Modules\Shopping\Services\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The buyer's side of a request for quotation.
 *
 * Accepting is the interesting action: it does not simply mark a row, it
 * makes a cart line at the quoted price, and the quotation id rides along so
 * that the price on the order can be traced back to the offer that produced
 * it.
 */
class QuotationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly QuotationService $quotations) {}

    public function index(Request $request): Response
    {
        $quotations = Quotation::query()
            ->forBuyer($this->currentUser($request))
            ->with(['seller', 'product.media', 'variant'])
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Quotation $quotation): array => QuotationResource::make($quotation)->resolve($request));

        return Inertia::render('storefront/Quotations', [
            'quotations' => $quotations,
        ]);
    }

    public function store(QuotationRequestRequest $request): RedirectResponse
    {
        $variant = ProductVariant::query()->whereKey($request->validated('variant_id'))->firstOrFail();

        try {
            $this->quotations->request(
                buyer: $request->user(),
                variant: $variant,
                quantity: (int) $request->validated('quantity'),
                message: $request->validated('message'),
            );
        } catch (ListingNotPurchasable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your request has been sent. The seller will come back to you with a price.'),
        ]);

        return back();
    }

    /**
     * Take the quoted price. It becomes a cart line at that price.
     */
    public function accept(Request $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('accept', $quotation);

        try {
            $this->quotations->accept($quotation);
        } catch (QuotationNotAcceptable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Quote accepted. It is in your cart at the quoted price.'),
        ]);

        return to_route('cart.index');
    }
}
