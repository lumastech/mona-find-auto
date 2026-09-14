<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Resources\RefundResource;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Finance task queue for refunds that code cannot finish.
 *
 * Card refunds go through Lenco's own process by hand, and a refund whose
 * transfer bounced needs sending another way. Both land here. Marking one
 * completed is an assertion that the money really moved, so it is a policy
 * check and an audit row, not a checkbox.
 */
class RefundQueueController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Refund::class);

        $refunds = Refund::query()
            ->with(['order.seller', 'buyer'])
            ->needingAttention()
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/refunds/Index', [
            'refunds' => RefundResource::collection($refunds)->resolve($request),
            'pagination' => [
                'currentPage' => $refunds->currentPage(),
                'lastPage' => $refunds->lastPage(),
                'total' => $refunds->total(),
            ],
        ]);
    }

    /**
     * Finance has pushed a manual refund through by hand.
     */
    public function complete(Request $request, Refund $refund): RedirectResponse
    {
        Gate::authorize('process', $refund);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->refunds->completeManually($refund, $this->currentUser($request), $validated['note'] ?? null);

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Refund :reference marked as paid.', ['reference' => $refund->reference]),
        ]);
    }
}
