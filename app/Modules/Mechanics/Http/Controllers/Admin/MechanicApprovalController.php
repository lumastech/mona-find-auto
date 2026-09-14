<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Mechanics\Exceptions\InvalidMechanicTransition;
use App\Modules\Mechanics\Http\Requests\Admin\MechanicDecisionRequest;
use App\Modules\Mechanics\Http\Requests\Admin\RejectMechanicRequest;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Services\MechanicApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A reviewer moving a mechanic's application through the workflow.
 *
 * Every action is gated by the policy, audited by the service, and checked
 * against the transitions the status itself allows — a reviewer cannot
 * approve a profile that was never submitted, however they got to the URL.
 */
class MechanicApprovalController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly MechanicApprovalService $approval) {}

    public function beginReview(MechanicDecisionRequest $request, MechanicProfile $mechanic): RedirectResponse
    {
        return $this->apply(
            $request,
            $mechanic,
            fn () => $this->approval->beginReview($mechanic, $this->currentUser($request), $request->note()),
            __('Application opened for review.'),
        );
    }

    public function approve(MechanicDecisionRequest $request, MechanicProfile $mechanic): RedirectResponse
    {
        return $this->apply(
            $request,
            $mechanic,
            fn () => $this->approval->approve($mechanic, $this->currentUser($request), $request->note()),
            __(':name is approved and listed in the directory.', ['name' => $mechanic->display_name]),
        );
    }

    public function reject(RejectMechanicRequest $request, MechanicProfile $mechanic): RedirectResponse
    {
        return $this->apply(
            $request,
            $mechanic,
            fn () => $this->approval->reject($mechanic, $this->currentUser($request), $request->reason(), $request->note()),
            __('Application rejected and the mechanic told why.'),
        );
    }

    public function suspend(RejectMechanicRequest $request, MechanicProfile $mechanic): RedirectResponse
    {
        return $this->apply(
            $request,
            $mechanic,
            fn () => $this->approval->suspend($mechanic, $this->currentUser($request), $request->reason()),
            __(':name has been taken off the directory.', ['name' => $mechanic->display_name]),
        );
    }

    public function reinstate(RejectMechanicRequest $request, MechanicProfile $mechanic): RedirectResponse
    {
        return $this->apply(
            $request,
            $mechanic,
            fn () => $this->approval->reinstate($mechanic, $this->currentUser($request), $request->reason()),
            __(':name is back in the directory.', ['name' => $mechanic->display_name]),
        );
    }

    /**
     * Authorise, run the transition, and turn a workflow refusal into a
     * message on the page rather than an error screen.
     *
     * @param  callable(): MechanicProfile  $transition
     */
    private function apply(
        Request $request,
        MechanicProfile $mechanic,
        callable $transition,
        string $message,
    ): RedirectResponse {
        Gate::forUser($this->currentUser($request))->authorize('approve', $mechanic);

        try {
            $transition();
        } catch (InvalidMechanicTransition $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.mechanics.show', $mechanic);
    }
}
