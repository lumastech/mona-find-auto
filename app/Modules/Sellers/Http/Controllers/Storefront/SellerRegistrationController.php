<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\LocationDirectory;
use App\Modules\Sellers\Enums\PayoutMethod;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Enums\RegistrationStep;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Exceptions\RegistrationIncomplete;
use App\Modules\Sellers\Http\Requests\Storefront\RegistrationStepRequest;
use App\Modules\Sellers\Http\Resources\PayoutAccountResource;
use App\Modules\Sellers\Http\Resources\SellerPolicyResource;
use App\Modules\Sellers\Models\SellerRegistrationDraft;
use App\Modules\Sellers\Services\PayoutAccountService;
use App\Modules\Sellers\Services\SellerDocumentService;
use App\Modules\Sellers\Services\SellerPolicyService;
use App\Modules\Sellers\Services\SellerRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The seller sign-up wizard.
 *
 * This lives on the storefront rather than in the seller portal, because the
 * person filling it in is a buyer who does not have the seller role yet —
 * they are given it when they submit.
 *
 * The wizard is resumable: it asks for a bank account, a PACRA certificate
 * and an ID, and nobody has all three on the desk in front of them.
 */
class SellerRegistrationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly SellerRegistrationService $registration,
        private readonly SellerPolicyService $policies,
        private readonly PayoutAccountService $payouts,
        private readonly SellerDocumentService $documents,
        private readonly LocationDirectory $locations,
    ) {}

    /**
     * Show a step, resuming wherever the applicant left off.
     */
    public function show(Request $request, ?RegistrationStep $step = null): Response|RedirectResponse
    {
        $user = $this->currentUser($request);

        /* Somebody who already runs a shop belongs in the portal, not here. */
        if ($user->seller !== null && $user->seller->verification_status !== VerificationStatus::Draft) {
            return to_route('seller.verification.show');
        }

        $draft = $this->registration->draftFor($user);
        $step ??= $draft->current_step;

        /* Nobody jumps to the end: an unreachable step lands on the furthest one reached. */
        if (! $draft->canOpen($step)) {
            return to_route('sellers.register.step', ['step' => $draft->current_step->value]);
        }

        $this->registration->moveTo($draft, $step);

        return Inertia::render('storefront/sellers/Register', [
            'step' => $step->value,
            'steps' => RegistrationStep::options(),
            'draft' => $this->draftPayload($draft),
            'completeness' => $this->registration->completeness($draft),
            ...$this->optionsForStep($step, $draft),
        ]);
    }

    /**
     * Save one step and move on.
     */
    public function store(RegistrationStepRequest $request, RegistrationStep $step): RedirectResponse
    {
        $user = $this->currentUser($request);
        $draft = $this->registration->draftFor($user);

        $draft = $this->registration->saveStep($draft, $step, $request->answers(), $user);

        return to_route('sellers.register.step', ['step' => $draft->current_step->value]);
    }

    /**
     * Send the application to MonaFind.
     */
    public function submit(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $draft = $this->registration->draftFor($user);

        try {
            $this->registration->submit($draft, $user);
        } catch (RegistrationIncomplete $exception) {
            throw ValidationException::withMessages(['submit' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your application is with MonaFind. We will be in touch.'),
        ]);

        return to_route('seller.verification.show');
    }

    /**
     * Everything the wizard needs to redraw itself where the applicant left it.
     *
     * @return array<string, mixed>
     */
    private function draftPayload(SellerRegistrationDraft $draft): array
    {
        $seller = $draft->seller;

        return [
            'current_step' => $draft->current_step->value,
            'furthest_step' => $draft->furthest_step->value,
            'answers' => $draft->answers(),
            'submitted' => $draft->isSubmitted(),
            'seller' => $seller === null ? null : [
                'id' => $seller->id,
                'business_name' => $seller->business_name,
                'type' => $seller->type->value,
                'type_label' => $seller->type->label(),
                'registration_number' => $seller->registration_number,
                'single_line' => $seller->singleLine(),
                'policies' => SellerPolicyResource::collection($seller->currentPolicies()->get())->resolve(),
                'payout_accounts' => PayoutAccountResource::collection($seller->payoutAccounts()->get())->resolve(),
                'documents' => $this->documents->summarise($seller),
            ],
        ];
    }

    /**
     * The reference data one step needs. Loading the bank list or the whole
     * province tree on every step would be wasted work on a phone.
     *
     * @return array<string, mixed>
     */
    private function optionsForStep(RegistrationStep $step, SellerRegistrationDraft $draft): array
    {
        return match ($step) {
            RegistrationStep::Type => ['sellerTypes' => SellerType::options()],
            RegistrationStep::Business => [
                'provinces' => $this->locations->provincesWithCities(),
                'sellerTypes' => SellerType::options(),
                /* Null in every environment without a key; the picker falls back to the phone's own GPS. */
                'mapsApiKey' => config('services.google_maps.browser_key'),
            ],
            RegistrationStep::Policies => [
                'policyTypes' => PolicyType::options(),
                'platformMinimumRefund' => $this->policies->platformMinimumRefund(),
            ],
            RegistrationStep::Payout => [
                'payoutMethods' => PayoutMethod::options(),
                'banks' => array_map(static fn ($bank): array => $bank->toArray(), $this->payouts->banks()),
            ],
            RegistrationStep::Documents, RegistrationStep::Review => [
                'platformMinimumRefund' => $this->policies->platformMinimumRefund(),
            ],
        };
    }
}
