<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Models\CommissionInvoice;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Support\MonetisationBreakdown;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Raises MonaFind's invoice to a seller, once per order, at the moment the
 * revenue is recognised.
 *
 * Which moment that is matters: escrow release or a direct payment, never a
 * payment into escrow. Money merely arriving is not money earned, and
 * invoicing an order that is refunded the following day would mean issuing a
 * credit note for revenue the platform never had.
 *
 * The invoice covers commission plus VAT on that commission and nothing else.
 * Product prices are VAT-inclusive and the seller answers for their own
 * output tax on the goods; the add-on and referral fees are deductions from a
 * payout rather than services MonaFind is invoicing for.
 *
 * Numbering is a gapless per-year series, which is what a tax authority
 * expects of an invoice sequence. That is also why the number is allocated
 * under a lock and protected by a unique index rather than derived from the
 * row id: ids skip on a rolled-back transaction, and a skipped invoice number
 * is a question somebody has to answer.
 */
class CommissionInvoiceService
{
    /**
     * Invoice the commission on an order, if it has not been invoiced.
     *
     * Idempotent by the unique index on `order_id`, so the listener that
     * calls it inherits the same replay safety the ledger entry beside it has.
     */
    public function issueFor(Order $order, MonetisationBreakdown $breakdown, ?JournalEntry $entry = null): CommissionInvoice
    {
        $existing = CommissionInvoice::query()->where('order_id', $order->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): CommissionInvoice => $this->write($order, $breakdown, $entry));
        } catch (UniqueConstraintViolationException $exception) {
            /* Two workers released the same escrow at once; one of them wins. */
            return CommissionInvoice::query()->where('order_id', $order->getKey())->first() ?? throw $exception;
        }
    }

    private function write(Order $order, MonetisationBreakdown $breakdown, ?JournalEntry $entry): CommissionInvoice
    {
        $year = (int) now()->format('Y');
        $sequence = $this->nextNumberFor($year);
        $snapshot = $order->monetisation();

        $invoice = CommissionInvoice::query()->create([
            'number' => sprintf('MFA-INV-%d-%05d', $year, $sequence),
            'series_year' => $year,
            'series_number' => $sequence,
            'seller_id' => $order->seller_id,
            'order_id' => $order->getKey(),
            'journal_entry_id' => $entry?->getKey(),
            'goods_ngwee' => $breakdown->goods->ngwee,
            'commission_ngwee' => $breakdown->commission->ngwee,
            'addon_fee_ngwee' => $breakdown->addonFee->ngwee,
            'referral_fee_ngwee' => $breakdown->referralFee->ngwee,
            'vat_ngwee' => $breakdown->vatOnCommission->ngwee,
            'total_ngwee' => $breakdown->invoiceTotal()->ngwee,
            'vat_rate_percent' => $snapshot->vatOnCommissionPercent ?? '0.00',
            'monetisation_snapshot' => $order->monetisation_snapshot ?? [],
            'issued_at' => now(),
        ]);

        audit(
            null,
            'commission_invoice.issued',
            $invoice,
            null,
            ['number' => $invoice->number, 'total_ngwee' => $invoice->total_ngwee->ngwee, 'order' => $order->number],
            'Commission recognised on order '.$order->number.'.',
        );

        return $invoice;
    }

    /**
     * The next number in this year's series.
     *
     * Read under a lock so two releases landing at the same instant queue
     * behind each other rather than both reading the same last number. The
     * unique index on (series_year, series_number) is the backstop for the
     * case the lock cannot cover — a database that does not take one.
     */
    private function nextNumberFor(int $year): int
    {
        $last = CommissionInvoice::query()
            ->where('series_year', $year)
            ->lockForUpdate()
            ->max('series_number');

        return (int) $last + 1;
    }
}
