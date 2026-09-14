<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\PayoutBatchStatus;
use App\Modules\Payments\Exceptions\PayoutNotAllowed;
use App\Modules\Payments\Http\Requests\Admin\ApprovePayoutBatchRequest;
use App\Modules\Payments\Http\Resources\PayoutBatchResource;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Services\PayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance's payout console.
 *
 * The approval screen is the point of this module's dual control, so it shows
 * the approver what they are signing for — the total, the line count, and who
 * built it — and the approve button is absent, not merely disabled, for the
 * person who built it. `$batch->isApprovableBy()` decides both that and the
 * server-side refusal, so the two can never disagree.
 */
class PayoutBatchController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly PayoutService $payouts) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PayoutBatch::class);

        $batches = PayoutBatch::query()
            ->with(['preparedBy', 'approvedBy'])
            ->withCount('lines')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/payouts/Index', [
            'batches' => PayoutBatchResource::collection($batches)->resolve($request),
            'pagination' => [
                'currentPage' => $batches->currentPage(),
                'lastPage' => $batches->lastPage(),
                'total' => $batches->total(),
            ],
            'statuses' => array_map(
                static fn (PayoutBatchStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                PayoutBatchStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, PayoutBatch $batch): Response
    {
        Gate::authorize('view', $batch);

        $batch->load(['lines.seller', 'lines.account', 'preparedBy', 'approvedBy']);

        return Inertia::render('admin/payouts/Show', [
            'batch' => PayoutBatchResource::make($batch)->withLines()->resolve($request),
            'canApprove' => $request->user()?->can('approve', $batch) ?? false,
            'canCancel' => $request->user()?->can('cancel', $batch) ?? false,
            'progress' => $this->progressFor($batch),
        ]);
    }

    /**
     * Where the transfers have got to, for the live execution view.
     *
     * Money out is never retried automatically, so a run either finishes or
     * stops needing a person. This is what tells the person watching which of
     * those happened, and it is the ONLY thing the screen polls for — a
     * partial reload of `progress` rather than the whole page, so an operator
     * watching a batch of two hundred lines is not re-rendering two hundred
     * rows every few seconds.
     *
     * `settled` says whether polling should stop. It is derived from the line
     * states rather than from the batch status because a batch whose last
     * line went Unresolved is finished as far as the machine is concerned and
     * very much not finished as far as the operator is concerned.
     *
     * @return array{
     *     live: bool,
     *     settled: bool,
     *     counts: array<string, int>,
     *     outstanding: int,
     *     needingAttention: int,
     *     paidNgwee: int,
     *     failedNgwee: int
     * }
     */
    private function progressFor(PayoutBatch $batch): array
    {
        $counts = [];
        $outstanding = 0;
        $needingAttention = 0;

        foreach ($batch->lines as $line) {
            $counts[$line->status->value] = ($counts[$line->status->value] ?? 0) + 1;

            if (! $line->status->isFinal()) {
                $outstanding++;
            }

            if ($line->status->needsAttention()) {
                $needingAttention++;
            }
        }

        return [
            /* Poll only while there is something left to watch. */
            'live' => $batch->status->hasStarted() && $outstanding > 0,
            'settled' => $outstanding === 0,
            'counts' => $counts,
            'outstanding' => $outstanding,
            'needingAttention' => $needingAttention,
            'paidNgwee' => $batch->paid_ngwee->ngwee,
            'failedNgwee' => $batch->failed_ngwee->ngwee,
        ];
    }

    /**
     * Build a batch on demand, outside the nightly schedule.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', PayoutBatch::class);

        $batch = $this->payouts->build($this->currentUser($request));

        return to_route('admin.payouts.show', $batch)->with('toast', [
            'type' => $batch->line_count > 0 ? 'success' : 'info',
            'message' => $batch->line_count > 0
                ? __(':count sellers are ready to be paid.', ['count' => $batch->line_count])
                : __('No sellers are owed enough to pay out right now.'),
        ]);
    }

    /**
     * Approve and immediately queue the transfers.
     */
    public function approve(ApprovePayoutBatchRequest $request, PayoutBatch $batch): RedirectResponse
    {
        Gate::authorize('approve', $batch);

        try {
            $this->payouts->approve($batch, $this->currentUser($request), $request->string('note')->value() ?: null);
            $this->payouts->execute($batch->refresh());
        } catch (PayoutNotAllowed $exception) {
            return back()->withErrors(['batch' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Approved. :count transfers are on their way.', ['count' => $batch->line_count]),
        ]);
    }

    public function cancel(Request $request, PayoutBatch $batch): RedirectResponse
    {
        Gate::authorize('cancel', $batch);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->payouts->cancel($batch, $this->currentUser($request), $validated['reason']);
        } catch (PayoutNotAllowed $exception) {
            return back()->withErrors(['batch' => $exception->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('Batch cancelled.')]);
    }
}
