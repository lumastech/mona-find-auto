<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Sellers\Exceptions\InvalidVerificationTransition;
use App\Modules\Sellers\Http\Requests\Admin\RejectSellerRequest;
use App\Modules\Sellers\Http\Requests\Admin\ScheduleInspectionRequest;
use App\Modules\Sellers\Http\Requests\Admin\VerificationDecisionRequest;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A reviewer moving a seller's application through the workflow.
 *
 * Every action here is gated by the policy, audited by the service, and
 * checked against the transitions the status itself allows — a reviewer
 * cannot verify a seller who was never submitted, however they got to the URL.
 */
class SellerVerificationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly SellerVerificationService $verification) {}

    /**
     * Pick the file up.
     */
    public function beginReview(VerificationDecisionRequest $request, Seller $seller): RedirectResponse
    {
        return $this->apply(
            fn () => $this->verification->beginReview($seller, $this->currentUser($request), $request->note()),
            $seller,
            __('Application opened for review.'),
        );
    }

    /**
     * Book a visit to the premises.
     */
    public function scheduleInspection(ScheduleInspectionRequest $request, Seller $seller): RedirectResponse
    {
        $when = $request->inspectionDate();

        return $this->apply(
            fn () => $this->verification->scheduleInspection($seller, $this->currentUser($request), $when, $request->note()),
            $seller,
            __('Inspection booked for :date.', ['date' => $when->toDayDateTimeString()]),
        );
    }

    /**
     * Grant the badge.
     */
    public function verify(VerificationDecisionRequest $request, Seller $seller): RedirectResponse
    {
        return $this->apply(
            fn () => $this->verification->verify($seller, $this->currentUser($request), $request->note(), $request->checklist()),
            $seller,
            __(':business is now verified.', ['business' => $seller->business_name]),
        );
    }

    /**
     * Turn the application down.
     */
    public function reject(RejectSellerRequest $request, Seller $seller): RedirectResponse
    {
        return $this->apply(
            fn () => $this->verification->reject($seller, $this->currentUser($request), $request->reason(), $request->note()),
            $seller,
            __('Application rejected and the seller told why.'),
        );
    }

    /**
     * Take a verified seller down.
     */
    public function suspend(RejectSellerRequest $request, Seller $seller): RedirectResponse
    {
        $this->authorizeSuspension($request, $seller);

        return $this->apply(
            fn () => $this->verification->suspend($seller, $this->currentUser($request), $request->reason()),
            $seller,
            __(':business is suspended and their listings are hidden.', ['business' => $seller->business_name]),
        );
    }

    /**
     * Put a suspended seller back.
     */
    public function reinstate(RejectSellerRequest $request, Seller $seller): RedirectResponse
    {
        $this->authorizeSuspension($request, $seller);

        return $this->apply(
            fn () => $this->verification->reinstate($seller, $this->currentUser($request), $request->reason()),
            $seller,
            __(':business is back and verified.', ['business' => $seller->business_name]),
        );
    }

    /**
     * Run a transition, turning a workflow refusal into a message on the page
     * rather than an error screen — a reviewer with a stale tab open is a
     * mistake to explain, not a crash.
     *
     * @param  callable(): Seller  $transition
     */
    private function apply(callable $transition, Seller $seller, string $message): RedirectResponse
    {
        try {
            $transition();
        } catch (InvalidVerificationTransition $exception) {
            throw ValidationException::withMessages(['verification' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.sellers.show', $seller);
    }

    private function authorizeSuspension(RejectSellerRequest $request, Seller $seller): void
    {
        abort_unless($this->currentUser($request)->can('suspend', $seller), 403);
    }
}
