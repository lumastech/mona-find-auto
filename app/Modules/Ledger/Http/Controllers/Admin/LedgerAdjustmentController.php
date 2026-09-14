<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Ledger\Enums\AdjustmentStatus;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Exceptions\AdjustmentNotAllowed;
use App\Modules\Ledger\Exceptions\LedgerException;
use App\Modules\Ledger\Http\Requests\Admin\LedgerAdjustmentRequest;
use App\Modules\Ledger\Http\Resources\LedgerAdjustmentResource;
use App\Modules\Ledger\Models\LedgerAdjustment;
use App\Modules\Ledger\Services\LedgerAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The manual adjustment queue, under dual control.
 *
 * Finance drafts and a platform administrator decides, and the two can never
 * be the same person. That rule is enforced in the service and in the policy
 * both — the policy is what hides the button, and the service is what makes
 * the rule true.
 *
 * Drafting posts nothing at all, which is why this screen can afford to be
 * forgiving: an unbalanced draft is a validation error in front of the person
 * who can still fix it, rather than a correction to an append-only table.
 */
class LedgerAdjustmentController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly LedgerAdjustmentService $adjustments) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', LedgerAdjustment::class);

        $filters = $request->validate(['status' => ['nullable', 'string', 'max:16']]);
        $status = AdjustmentStatus::tryFrom($filters['status'] ?? '');

        $adjustments = LedgerAdjustment::query()
            ->with(['author', 'decider', 'entry'])
            ->withStatus($status)
            /* Pending first: this is a queue, and a queue that opens on the archive is one nobody works. */
            ->orderByRaw("CASE WHEN status = '".AdjustmentStatus::Pending->value."' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (LedgerAdjustment $adjustment): array => LedgerAdjustmentResource::make($adjustment)->resolve($request));

        return Inertia::render('admin/finance/adjustments/Index', [
            'adjustments' => $adjustments,
            'filters' => ['status' => $status?->value],
            'statuses' => AdjustmentStatus::options(),
            'accounts' => LedgerAccountCode::options(),
            'directions' => array_map(
                static fn (EntryDirection $direction): array => ['value' => $direction->value, 'label' => $direction->label()],
                EntryDirection::cases(),
            ),
            'canCreate' => $request->user()?->can('create', LedgerAdjustment::class) ?? false,
        ]);
    }

    public function store(LedgerAdjustmentRequest $request): RedirectResponse
    {
        Gate::authorize('create', LedgerAdjustment::class);

        try {
            $this->adjustments->draft(
                $request->lines(),
                (string) $request->validated('description'),
                (string) $request->validated('reason'),
                $this->currentUser($request),
            );
        } catch (LedgerException $exception) {
            /*
             * The ledger's own invariants, turned into a field error. The
             * commonest by far is a draft that does not balance, and the
             * exception message already says by how much.
             */
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }

        return back()->with('success', __('Adjustment drafted. It moves no money until an administrator approves it.'));
    }

    public function approve(Request $request, LedgerAdjustment $adjustment): RedirectResponse
    {
        Gate::authorize('decide', $adjustment);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        try {
            $this->adjustments->approve($adjustment, $this->currentUser($request), $note);
        } catch (AdjustmentNotAllowed $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Adjustment approved and posted.'));
    }

    public function reject(Request $request, LedgerAdjustment $adjustment): RedirectResponse
    {
        Gate::authorize('decide', $adjustment);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        try {
            $this->adjustments->reject($adjustment, $this->currentUser($request), $note);
        } catch (AdjustmentNotAllowed $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Adjustment rejected. Nothing was posted.'));
    }
}
