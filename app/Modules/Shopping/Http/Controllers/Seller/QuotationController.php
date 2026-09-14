<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use App\Modules\Shopping\Enums\QuotationStatus;
use App\Modules\Shopping\Exceptions\InvalidQuotationTransition;
use App\Modules\Shopping\Http\Requests\Seller\QuotationDeclineRequest;
use App\Modules\Shopping\Http\Requests\Seller\QuotationResponseRequest;
use App\Modules\Shopping\Http\Resources\QuotationResource;
use App\Modules\Shopping\Models\Quotation;
use App\Modules\Shopping\Services\QuotationService;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The RFQ inbox: what buyers have asked this shop, and what it answered.
 *
 * Opens on the unanswered ones. A request nobody replies to is worse for the
 * platform than a request declined — the buyer waits, then goes back to
 * WhatsApp — so the default filter is the work, not the archive.
 *
 * Every write goes through the policy first. Holding the seller role is not
 * the same as being the shop that was asked, and a shop that could be
 * committed to a price by another shop's account is not a marketplace anybody
 * would trade on.
 */
class QuotationController extends Controller
{
    use ResolvesCurrentSeller;

    public function __construct(private readonly QuotationService $quotations) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
        ]);

        $status = QuotationStatus::tryFrom($filters['status'] ?? '');

        $quotations = Quotation::query()
            ->forSeller($seller)
            ->with(['buyer', 'product.media', 'variant'])
            ->when($status, fn ($query, QuotationStatus $status) => $query->where('status', $status))
            /* Unanswered first, then oldest first: the buyer who has waited longest. */
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [QuotationStatus::Open->value])
            ->oldest('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Quotation $quotation): array => QuotationResource::make($quotation)->resolve($request));

        return Inertia::render('seller/quotations/Index', [
            'quotations' => $quotations,
            'filters' => ['status' => $status?->value],
            'statusOptions' => QuotationStatus::options(),
            'awaitingCount' => $this->quotations->awaitingSellerCount($seller),
        ]);
    }

    /**
     * Answer with a price, a validity date and a delivery note.
     */
    public function respond(QuotationResponseRequest $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('quote', $quotation);

        try {
            $this->quotations->quote(
                quotation: $quotation,
                /* A kwacha string, read by Money. Nothing here is a float. */
                unitPrice: Money::ofKwacha((string) $request->validated('unit_price')),
                validUntil: $request->date('valid_until') ?? now(),
                deliveryNote: $request->validated('delivery_note'),
            );
        } catch (InvalidQuotationTransition $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your quote has been sent to the buyer.')]);

        return back();
    }

    public function decline(QuotationDeclineRequest $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('decline', $quotation);

        try {
            $this->quotations->decline($quotation, $request->validated('reason'));
        } catch (InvalidQuotationTransition $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request declined.')]);

        return back();
    }
}
