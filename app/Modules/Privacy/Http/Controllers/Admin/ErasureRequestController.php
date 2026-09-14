<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Privacy\Enums\ErasureStatus;
use App\Modules\Privacy\Http\Requests\BlockErasureRequest;
use App\Modules\Privacy\Models\ErasureRequest;
use App\Modules\Privacy\Services\ErasureGuard;
use App\Modules\Privacy\Services\ErasureRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff console's view of deletion requests.
 *
 * Staff can see what is coming and hold anything that has to settle first.
 * They cannot cancel a request and there is no route here that would let them
 * — see ErasureRequestPolicy.
 */
class ErasureRequestController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly ErasureRequestService $erasures,
        private readonly ErasureGuard $guard,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('staff');

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(ErasureStatus::cases(), 'value'))],
        ]);

        $requests = ErasureRequest::query()
            ->with(['user:id,name,email,status', 'blockedBy:id,name'])
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) => $query->where('status', ErasureStatus::from($status)),
                /* Default view is the work: what is still going to happen. */
                fn ($query) => $query->open(),
            )
            ->orderBy('erase_after')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ErasureRequest $erasure): array => [
                'id' => $erasure->getKey(),
                'status' => $erasure->status->value,
                'status_label' => $erasure->status->label(),
                'requested_at' => $erasure->requested_at->toIso8601String(),
                'erase_after' => $erasure->erase_after->toIso8601String(),
                'completed_at' => $erasure->completed_at?->toIso8601String(),
                'blocked_reason' => $erasure->blocked_reason,
                'blocked_by' => $erasure->blockedBy?->name,
                'records_erased' => $erasure->recordsErased(),
                /*
                 * The account is shown by id once erased. Its name column is
                 * a tombstone by then, and rendering "Former MonaFind user"
                 * in a list of five would make them look like one account.
                 */
                'account' => [
                    'id' => $erasure->user_id,
                    'name' => $erasure->status === ErasureStatus::Completed ? null : $erasure->user->name,
                    'email' => $erasure->status === ErasureStatus::Completed ? null : $erasure->user->email,
                ],
                /* Live, not stored: what would hold this request up right now. */
                'blockers' => $erasure->status->isOpen() ? $this->guard->blockersFor($erasure->user) : [],
            ]);

        return Inertia::render('admin/privacy/ErasureRequests', [
            'requests' => $requests,
            'filters' => $filters,
            'statuses' => array_map(
                static fn (ErasureStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                ErasureStatus::cases(),
            ),
        ]);
    }

    /**
     * Hold a request while something settles.
     */
    public function block(BlockErasureRequest $request, ErasureRequest $erasureRequest): RedirectResponse
    {
        Gate::authorize('block', $erasureRequest);

        $this->erasures->block($erasureRequest, $request->reason(), $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The deletion is on hold.')]);

        return back();
    }

    /**
     * Lift the hold. The grace period is not restarted — it has already run.
     */
    public function release(Request $request, ErasureRequest $erasureRequest): RedirectResponse
    {
        Gate::authorize('release', $erasureRequest);

        $this->erasures->release($erasureRequest, $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The deletion will go ahead.')]);

        return back();
    }
}
