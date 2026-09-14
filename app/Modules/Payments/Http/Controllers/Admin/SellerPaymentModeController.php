<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\PaymentModeService;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deciding whether a seller is paid on completion or on payment.
 *
 * The screen shows the four eligibility criteria and how this seller measures
 * against each, then lets an administrator do whatever they judge right. The
 * criteria are advice: MonaFind may know a supplier personally, or want to
 * win one. What the override cannot be is silent, so a reason is required
 * whenever the decision goes against the recommendation and every change is
 * audited with the eligibility figures as they stood at the time.
 */
class SellerPaymentModeController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly PaymentModeService $modes) {}

    public function edit(Request $request, Seller $seller): Response
    {
        Gate::authorize('setCommercialTerms', $seller);

        $eligibility = $this->modes->eligibility($seller);

        return Inertia::render('admin/sellers/PaymentMode', [
            'seller' => [
                'id' => $seller->getKey(),
                'name' => $seller->business_name,
                'slug' => $seller->slug,
                'paymentMode' => $seller->payment_mode->value,
                'paymentModeLabel' => $seller->payment_mode->label(),
            ],
            'eligibility' => $eligibility->toArray(),
            'modes' => array_map(static fn (PaymentMode $mode): array => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'description' => $mode->description(),
            ], PaymentMode::cases()),
            'reservePercent' => (float) settings('risk.reserve_percent', 10),
            'disputeThresholdPercent' => $this->modes->threshold(),
        ]);
    }

    public function update(Request $request, Seller $seller): RedirectResponse
    {
        Gate::authorize('setCommercialTerms', $seller);

        $validated = $request->validate([
            'payment_mode' => ['required', 'string', 'in:escrow,direct'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $mode = PaymentMode::from($validated['payment_mode']);
        $eligibility = $this->modes->eligibility($seller);

        /*
         * A recommendation that is being ignored has to be explained. This is
         * the whole value of the override: the record of why, written at the
         * moment of deciding rather than reconstructed after a bad debt.
         */
        if ($mode === PaymentMode::Direct && ! $eligibility->isEligible() && blank($validated['reason'] ?? null)) {
            return back()->withErrors([
                'reason' => __('This seller does not meet the criteria for direct settlement. Give a reason for the override.'),
            ]);
        }

        $this->modes->setMode($seller, $mode, $this->currentUser($request), $validated['reason'] ?? null);

        return back()->with('toast', [
            'type' => 'success',
            'message' => __(':seller is now on :mode settlement.', [
                'seller' => $seller->business_name,
                'mode' => $mode->label(),
            ]),
        ]);
    }
}
