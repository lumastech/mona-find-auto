<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Exceptions\DisputeNotAllowed;
use App\Modules\Orders\Http\Requests\Storefront\OpenDisputeRequest;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The buyer raising a problem.
 *
 * One action, deliberately. There is no editing and no withdrawing: a dispute
 * is a claim about what happened, and a claim that can be rewritten after the
 * seller has answered it is not evidence. A buyer who was mistaken says so in
 * the moderator's thread and the moderator resolves it as a release.
 */
class DisputeController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly DisputeService $disputes) {}

    public function store(OpenDisputeRequest $request, Order $order): RedirectResponse
    {
        Gate::authorize('act', $order);

        try {
            $this->disputes->open(
                $order,
                $this->currentUser($request),
                $request->reason(),
                (string) $request->validated('details'),
                $request->photos(),
            );
        } catch (DisputeNotAllowed $exception) {
            return back()->withErrors(['dispute' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('We have your report. This order is on hold while we look at it.'),
        ]);
    }
}
