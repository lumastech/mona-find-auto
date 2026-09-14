<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\SellerStatement;
use App\Modules\Finance\Support\MetricWindow;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Closes a seller's month and writes it down.
 *
 * ## Closing, not reporting
 *
 * The distinction is the point of the class. A report answers a question now;
 * closing a month writes an answer down so that it stops depending on now.
 * Once a statement row exists, every figure a seller sees — on screen, in the
 * PDF, in the CSV — is read from it, and a backdated adjustment posted into
 * a closed month changes the ledger without changing the statement anybody
 * already downloaded.
 *
 * That is deliberate and it is the same decision CommissionInvoiceService
 * makes. The alternative, recomputing on every read, produces documents that
 * quietly disagree with the copies in a seller's own files.
 *
 * ## Re-running is safe
 *
 * The unique index on (seller, year, month) means the monthly job can be
 * re-run after a failure without rebuilding months that already closed. A
 * month already on file is returned untouched, never refreshed — see
 * `regenerate()` for the deliberate, audited exception.
 *
 * ## Where the figures come from
 *
 * All of them from the ledger, none from orders. Sales, fees and refunds are
 * read through the orders an entry references; payouts and the reserve are
 * read off the line's SUBJECT, because a payout entry references the payout
 * and has no trade to join to.
 */
class SellerStatementService
{
    public function __construct(private readonly FinanceMetrics $metrics) {}

    /**
     * Close a month for a seller, or hand back the one already on file.
     */
    public function generate(Seller $seller, CarbonInterface $month): SellerStatement
    {
        $window = MetricWindow::monthOf($month);
        $existing = $this->find($seller, $window);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): SellerStatement => $this->write($seller, $window));
        } catch (UniqueConstraintViolationException $exception) {
            /* Two workers closed the same month at once; one of them wins. */
            return $this->find($seller, $window) ?? throw $exception;
        }
    }

    /**
     * Rebuild a month that has already closed.
     *
     * Exists because a genuine correction — an adjustment approved after the
     * month closed — does sometimes have to reach a statement. It is separate
     * from generate() so that it can never happen by accident, and it writes
     * an audit row carrying both sets of figures, because a seller may be
     * holding the superseded copy.
     */
    public function regenerate(Seller $seller, CarbonInterface $month, ?string $reason = null): SellerStatement
    {
        $window = MetricWindow::monthOf($month);
        $existing = $this->find($seller, $window);

        $statement = DB::transaction(function () use ($seller, $window, $existing): SellerStatement {
            $existing?->delete();

            return $this->write($seller, $window);
        });

        audit(
            null,
            'seller_statement.regenerated',
            $statement,
            $existing?->only(['sales_ngwee', 'commission_ngwee', 'closing_payable_ngwee']),
            $statement->only(['sales_ngwee', 'commission_ngwee', 'closing_payable_ngwee']),
            $reason ?? 'Statement rebuilt against the ledger after a correction.',
        );

        return $statement;
    }

    /**
     * The sellers with anything to say for a month.
     *
     * Asked of the ledger rather than of the seller table, so a shop that did
     * not trade gets no statement instead of a page of zeroes.
     *
     * @return array<int, int>
     */
    public function sellersWithActivityIn(CarbonInterface $month): array
    {
        $window = MetricWindow::monthOf($month);
        [$from, $to] = $window->bounds();

        $fromOrders = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('orders', function ($join): void {
                $join->on('orders.id', '=', 'je.reference_id')
                    ->where('je.reference_type', '=', Order::class);
            })
            ->whereBetween('jl.posted_at', [$from, $to])
            ->distinct()
            ->pluck('orders.seller_id');

        $fromSubjects = DB::table('journal_lines')
            ->where('subject_type', Seller::class)
            ->whereBetween('posted_at', [$from, $to])
            ->distinct()
            ->pluck('subject_id');

        return array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            [...$fromOrders->all(), ...$fromSubjects->all()],
        )));
    }

    private function find(Seller $seller, MetricWindow $window): ?SellerStatement
    {
        return SellerStatement::query()
            ->where('seller_id', $seller->getKey())
            ->where('period_year', (int) $window->from->format('Y'))
            ->where('period_month', (int) $window->from->format('n'))
            ->first();
    }

    private function write(Seller $seller, MetricWindow $window): SellerStatement
    {
        $sellerId = (int) $seller->getKey();

        $totals = $this->metrics->totalsForSeller($window, $sellerId);
        $reserve = $this->metrics->sellerReserveMovement($window, $sellerId);

        return SellerStatement::query()->create([
            'seller_id' => $sellerId,
            'period_year' => (int) $window->from->format('Y'),
            'period_month' => (int) $window->from->format('n'),
            'period_start' => $window->from->toDateString(),
            'period_end' => $window->to->toDateString(),

            'order_count' => $totals->orderCount,
            'sales_ngwee' => $totals->gmv->ngwee,

            'commission_ngwee' => $totals->commission->ngwee,
            'addon_fee_ngwee' => $totals->addonFees->ngwee,
            'referral_fee_ngwee' => $totals->referralFees->ngwee,
            'vat_on_commission_ngwee' => $totals->vatOnCommission->ngwee,

            'refunds_ngwee' => $totals->refundsToBuyers->ngwee,
            'payouts_ngwee' => $this->metrics->sellerPayouts($window, $sellerId)->ngwee,

            'reserve_withheld_ngwee' => $reserve['withheld']->ngwee,
            'reserve_released_ngwee' => $reserve['released']->ngwee,

            'closing_payable_ngwee' => $this->metrics
                ->sellerClosingBalance($window, $sellerId, LedgerAccountCode::SellerPayable)->ngwee,
            'closing_reserve_ngwee' => $this->metrics
                ->sellerClosingBalance($window, $sellerId, LedgerAccountCode::SellerReserve)->ngwee,

            'generated_at' => now(),
        ]);
    }
}
