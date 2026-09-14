<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Payments\Jobs\ReconcileGatewayDay;
use App\Modules\Payments\Models\ReconciliationException;
use App\Modules\Payments\Models\ReconciliationRun;
use App\Modules\Payments\Services\PlatformCashCheck;
use App\Modules\Payments\Services\ReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What did not add up, and what was done about it.
 *
 * Read-only apart from resolving an exception, which is an annotation rather
 * than a fix — nothing here ever edits a payment or a ledger entry. An
 * operator who could "correct" a reconciliation exception from this screen
 * would be able to make the evidence of a problem disappear without the
 * problem going anywhere.
 */
class ReconciliationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly ReconciliationService $reconciliation,
        private readonly PlatformCashCheck $cashCheck,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-reconciliation');

        $runs = ReconciliationRun::query()
            ->withCount(['exceptions as outstanding_count' => fn ($query) => $query->whereNull('resolved_at')])
            ->latest('for_date')
            ->paginate(30);

        return Inertia::render('admin/reconciliation/Index', [
            'runs' => $runs->through(static fn (ReconciliationRun $run): array => [
                'id' => $run->getKey(),
                'date' => $run->for_date->toDateString(),
                'status' => $run->status->value,
                'statusLabel' => $run->status->label(),
                'exceptionCount' => $run->exception_count,
                'outstandingCount' => (int) $run->getAttribute('outstanding_count'),
                'gatewayTotalNgwee' => $run->gateway_total_ngwee->ngwee,
                'ledgerTotalNgwee' => $run->ledger_total_ngwee->ngwee,
                'varianceNgwee' => $run->variance_ngwee->ngwee,
                'url' => route('admin.reconciliation.show', $run),
            ])->items(),
            /*
             * The daily balance check: one number against one number, now.
             *
             * It belongs beside the nightly runs because it answers a
             * question they cannot. A run compares a DAY line by line and can
             * pass while the running totals are wrong — an entry posted to
             * the wrong account balances and reconciles perfectly. This
             * compares what the books say is at Lenco with what Lenco says,
             * and a drift there invalidates every other figure on the
             * platform.
             *
             * Deferred: it calls Lenco over the network, and the runs list is
             * what somebody opened this screen for.
             */
            'cashCheck' => Inertia::defer(fn (): array => $this->cashCheck->run()),
            'pagination' => [
                'currentPage' => $runs->currentPage(),
                'lastPage' => $runs->lastPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    public function show(Request $request, ReconciliationRun $run): Response
    {
        Gate::authorize('view-reconciliation');

        $run->load(['exceptions.resolvedBy', 'exceptions.payment']);

        return Inertia::render('admin/reconciliation/Show', [
            'run' => [
                'id' => $run->getKey(),
                'date' => $run->for_date->toDateString(),
                'status' => $run->status->value,
                'statusLabel' => $run->status->label(),
                'collectionsChecked' => $run->collections_checked,
                'settlementsChecked' => $run->settlements_checked,
                'transactionsChecked' => $run->transactions_checked,
                'gatewayTotalNgwee' => $run->gateway_total_ngwee->ngwee,
                'ledgerTotalNgwee' => $run->ledger_total_ngwee->ngwee,
                'varianceNgwee' => $run->variance_ngwee->ngwee,
                'failureReason' => $run->failure_reason,
            ],
            'exceptions' => $run->exceptions->map(static fn (ReconciliationException $exception): array => [
                'id' => $exception->getKey(),
                'type' => $exception->type->value,
                'typeLabel' => $exception->type->label(),
                'severity' => $exception->severity,
                'reference' => $exception->reference,
                'detail' => $exception->detail,
                'gatewayAmountNgwee' => $exception->gateway_amount_ngwee?->ngwee,
                'ledgerAmountNgwee' => $exception->ledger_amount_ngwee?->ngwee,
                'varianceNgwee' => $exception->variance_ngwee?->ngwee,
                'isResolved' => $exception->isResolved(),
                'resolutionNote' => $exception->resolution_note,
                'resolvedBy' => $exception->resolvedBy?->name,
            ])->values(),
        ]);
    }

    /**
     * Re-run a day by hand, after fixing whatever made it fail.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('view-reconciliation');

        $validated = $request->validate(['date' => ['required', 'date', 'before_or_equal:today']]);

        ReconcileGatewayDay::dispatch($validated['date']);

        return back()->with('toast', [
            'type' => 'info',
            'message' => __('Reconciliation for :date has been queued.', ['date' => $validated['date']]),
        ]);
    }

    public function resolve(Request $request, ReconciliationException $exception): RedirectResponse
    {
        Gate::authorize('view-reconciliation');

        $validated = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        $this->reconciliation->resolve($exception, $this->currentUser($request), $validated['note']);

        return back()->with('toast', ['type' => 'success', 'message' => __('Exception resolved.')]);
    }
}
