<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\CollectionResponse;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Integrations\Payments\Data\SettlementRecord;
use App\Models\User;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Payments\Enums\ReconciliationExceptionType;
use App\Modules\Payments\Enums\ReconciliationStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\ReconciliationException;
use App\Modules\Payments\Models\ReconciliationRun;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Does what Lenco thinks happened match what our books say happened?
 *
 * ## Why this exists at all
 *
 * Every other safeguard in this module is about one payment. This is the only
 * thing that looks at a whole day and asks whether anything fell through. The
 * failure it is built to catch is the worst one on the platform: a buyer whose
 * money left their wallet and whose order still says unpaid, because the
 * webhook was lost and the poller had already given up.
 *
 * ## Three comparisons, three different questions
 *
 * - Collections against Payment rows: did we record every payment Lenco took?
 * - Settlements against those collections: has the money actually arrived?
 * - Gateway transactions against platform_cash: do the day's totals agree?
 *
 * A run is kept even when it is clean, because "ran and found nothing" and
 * "never ran" are indistinguishable if only exceptions are stored.
 *
 * ## It never fixes anything
 *
 * Deliberately read-only. A reconciler that repaired what it found would hide
 * the very thing it exists to surface, and an automatic repair applied to a
 * misdiagnosed exception is far more expensive than a human reading a list.
 */
class ReconciliationService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Reconcile one day.
     *
     * Re-running a day replaces its previous run rather than adding a second,
     * so the dashboard cannot double-count.
     */
    public function run(CarbonInterface $date): ReconciliationRun
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        $run = $this->startRun($from);

        try {
            $collections = $this->gateway->collectionsBetween($from, $to);
            $settlements = $this->gateway->settlementsBetween($from, $to);
            $transactions = $this->gateway->transactionsBetween($from, $to);

            $exceptions = [
                ...$this->compareCollections($collections),
                ...$this->compareSettlements($settlements),
                ...$this->compareOrphans($collections, $from, $to),
            ];

            $gatewayTotal = $this->gatewayTotal($collections);
            $ledgerTotal = $this->ledgerCashIn($from, $to);
            $variance = $gatewayTotal->minus($ledgerTotal);

            if (! $variance->isZero()) {
                $exceptions[] = [
                    'type' => ReconciliationExceptionType::LedgerVariance,
                    'detail' => __('The day\'s collections and the ledger\'s platform cash movement disagree.'),
                    'gateway_amount_ngwee' => $gatewayTotal,
                    'ledger_amount_ngwee' => $ledgerTotal,
                    'variance_ngwee' => $variance,
                ];
            }

            return $this->finishRun($run, $exceptions, [
                'collections_checked' => count($collections),
                'settlements_checked' => count($settlements),
                'transactions_checked' => count($transactions),
                'gateway_total_ngwee' => $gatewayTotal,
                'ledger_total_ngwee' => $ledgerTotal,
                'variance_ngwee' => $variance,
            ]);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => ReconciliationStatus::Failed,
                'failure_reason' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    /**
     * Every successful collection should have a successful Payment row for
     * the same reference and the same amount.
     *
     * @param  array<int, CollectionResponse>  $collections
     * @return array<int, array<string, mixed>>
     */
    private function compareCollections(array $collections): array
    {
        $exceptions = [];

        foreach ($collections as $collection) {
            if (! $collection->status->isSettled()) {
                continue;
            }

            $payment = Payment::query()
                ->where('reference', $collection->reference)
                ->where('status', PaymentStatus::Successful)
                ->first();

            if ($payment === null) {
                $exceptions[] = [
                    'type' => ReconciliationExceptionType::Missing,
                    'reference' => $collection->reference,
                    'lenco_id' => $collection->gatewayId,
                    'gateway_amount_ngwee' => $collection->amount,
                    'detail' => __('Lenco collected this payment but MonaFind has no successful payment record for it.'),
                    'context' => ['status' => $collection->status->value],
                ];

                continue;
            }

            if (! $payment->amount_ngwee->equals($collection->amount)) {
                $exceptions[] = [
                    'type' => ReconciliationExceptionType::AmountMismatch,
                    'reference' => $collection->reference,
                    'lenco_id' => $collection->gatewayId,
                    'payment_id' => $payment->getKey(),
                    'order_group_id' => $payment->order_group_id,
                    'gateway_amount_ngwee' => $collection->amount,
                    'ledger_amount_ngwee' => $payment->amount_ngwee,
                    'variance_ngwee' => $collection->amount->minus($payment->amount_ngwee),
                    'detail' => __('The gateway and our payment record disagree about how much was collected.'),
                ];
            }
        }

        return $exceptions;
    }

    /**
     * Collections that Lenco has taken but not yet settled to our account.
     *
     * Only worth flagging once it is overdue: next-day settlement means most
     * of a day's collections are legitimately unsettled when this runs.
     *
     * @param  array<int, SettlementRecord>  $settlements
     * @return array<int, array<string, mixed>>
     */
    private function compareSettlements(array $settlements): array
    {
        $overdueDays = (int) settings('payouts.settlement_overdue_days', 3);
        $exceptions = [];

        foreach ($settlements as $settlement) {
            if ($settlement->isSettled() || $settlement->collectionReference === null) {
                continue;
            }

            $payment = Payment::query()
                ->where('reference', $settlement->collectionReference)
                ->where('status', PaymentStatus::Successful)
                ->first();

            if ($payment === null || $payment->observed_at->diffInDays(now()) < $overdueDays) {
                continue;
            }

            $exceptions[] = [
                'type' => ReconciliationExceptionType::Unsettled,
                'reference' => $settlement->collectionReference,
                'lenco_id' => $settlement->id,
                'payment_id' => $payment->getKey(),
                'gateway_amount_ngwee' => $settlement->collectionAmount,
                'detail' => __('Collected :days days ago and still not settled to the MonaFind account.', [
                    'days' => (int) $payment->observed_at->diffInDays(now()),
                ]),
            ];
        }

        return $exceptions;
    }

    /**
     * Payments we believe succeeded that the gateway has no record of.
     *
     * Usually a hand-written row or a seeded fixture that escaped into a real
     * environment. Occasionally something much worse, which is why it is
     * flagged critical rather than swept up.
     *
     * @param  array<int, CollectionResponse>  $collections
     * @return array<int, array<string, mixed>>
     */
    private function compareOrphans(array $collections, CarbonInterface $from, CarbonInterface $to): array
    {
        $known = array_map(
            static fn (CollectionResponse $collection): string => $collection->reference,
            $collections,
        );

        return Payment::query()
            ->where('status', PaymentStatus::Successful)
            ->whereBetween('observed_at', [$from, $to])
            ->whereNotIn('reference', $known === [] ? [''] : $known)
            ->get()
            ->map(static fn (Payment $payment): array => [
                'type' => ReconciliationExceptionType::Orphan,
                'reference' => $payment->reference,
                'payment_id' => $payment->getKey(),
                'order_group_id' => $payment->order_group_id,
                'ledger_amount_ngwee' => $payment->amount_ngwee,
                'detail' => __('MonaFind recorded this payment as successful but the gateway has no record of it.'),
            ])
            ->all();
    }

    /**
     * What Lenco says it collected.
     *
     * @param  array<int, CollectionResponse>  $collections
     */
    private function gatewayTotal(array $collections): Money
    {
        return Money::sum(...array_map(
            static fn (CollectionResponse $collection): Money => $collection->status->isSettled()
                ? $collection->amount
                : Money::zero(),
            $collections,
        ));
    }

    /**
     * What our own books say came into platform cash.
     *
     * Debits only: platform_cash is an asset, so money IN is a debit and money
     * OUT — payouts, refunds — is a credit. Netting the two would compare a
     * day's collections against collections-minus-payouts and produce a
     * variance every single day.
     */
    private function ledgerCashIn(CarbonInterface $from, CarbonInterface $to): Money
    {
        $ngwee = (int) JournalLine::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('ledger_accounts.code', LedgerAccountCode::PlatformCash->value)
            ->where('journal_lines.direction', EntryDirection::Debit->value)
            ->whereBetween('journal_lines.posted_at', [$from, $to])
            ->sum('journal_lines.amount_ngwee');

        return Money::ofNgwee($ngwee);
    }

    /**
     * Start (or restart) the run for a date.
     */
    private function startRun(CarbonInterface $date): ReconciliationRun
    {
        return DB::transaction(function () use ($date): ReconciliationRun {
            $existing = ReconciliationRun::query()->whereDate('for_date', $date->toDateString())->first();

            $existing?->exceptions()->delete();

            $attributes = [
                'status' => ReconciliationStatus::Running,
                'started_at' => now(),
                'finished_at' => null,
                'failure_reason' => null,
                'exception_count' => 0,
            ];

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();

                return $existing;
            }

            return ReconciliationRun::create(['for_date' => $date->toDateString(), ...$attributes]);
        });
    }

    /**
     * Write the exceptions and close the run.
     *
     * @param  array<int, array<string, mixed>>  $exceptions
     * @param  array<string, mixed>  $totals
     */
    private function finishRun(ReconciliationRun $run, array $exceptions, array $totals): ReconciliationRun
    {
        return DB::transaction(function () use ($run, $exceptions, $totals): ReconciliationRun {
            foreach ($exceptions as $exception) {
                /** @var ReconciliationExceptionType $type */
                $type = $exception['type'];

                ReconciliationException::create([
                    'reconciliation_run_id' => $run->getKey(),
                    'severity' => $type->severity(),
                    ...$exception,
                ]);
            }

            $run->forceFill([
                ...$totals,
                'exception_count' => count($exceptions),
                'status' => $exceptions === [] ? ReconciliationStatus::Clean : ReconciliationStatus::Exceptions,
                'finished_at' => now(),
            ])->save();

            return $run->refresh();
        });
    }

    /**
     * Close an exception with an explanation.
     *
     * Never a delete. An exception that can be made to disappear is an
     * exception nobody has to explain.
     */
    public function resolve(ReconciliationException $exception, User $actor, string $note): ReconciliationException
    {
        $exception->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $actor->getKey(),
            'resolution_note' => $note,
        ])->save();

        audit($actor, 'payments.reconciliation.exception_resolved', $exception, null, [
            'type' => $exception->type->value,
            'reference' => $exception->reference,
        ], $note);

        return $exception->refresh();
    }
}
