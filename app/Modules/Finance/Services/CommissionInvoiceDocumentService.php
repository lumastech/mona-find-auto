<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Support\Pdf\FinanceStationery;
use App\Modules\Ledger\Models\CommissionInvoice;

/**
 * MonaFind's commission invoice, on paper.
 *
 * ## What this document is, and is not
 *
 * It invoices the COMMISSION and nothing else. Product prices on the platform
 * are VAT-inclusive and the seller answers for their own output tax on the
 * goods; MonaFind is a marketplace charging a fee, and the only tax figure it
 * calculates is the VAT on that fee. An invoice that showed the order total
 * would read as though MonaFind had sold the parts, which is both wrong and,
 * to a seller reconciling their ZRA return, actively misleading. The note at
 * the foot says so in as many words.
 *
 * The add-on and referral fees appear on the invoice as deductions taken from
 * the payout rather than as invoiced services, and carry no VAT of their own —
 * that is what the ledger recorded, so that is what the document says.
 *
 * ## Nothing is recomputed
 *
 * Every figure is read off the CommissionInvoice row, which copied them from
 * the order's snapshot at the moment revenue was recognised. Re-rendering an
 * invoice from 2026 in 2029 produces the same page, whatever the VAT rate has
 * done in between — and the rate the invoice was raised under is printed on
 * it, so the arithmetic can be checked by hand.
 */
class CommissionInvoiceDocumentService
{
    public function pdf(CommissionInvoice $invoice): string
    {
        $invoice->loadMissing(['seller', 'order']);

        $page = FinanceStationery::page();
        $y = $page->masthead('Tax invoice', $invoice->number, $invoice->issued_at);

        $y += 6;
        $sellerBottom = $page->block(FinanceStationery::MARGIN, $y, 'Invoiced to', [
            $invoice->seller->business_name,
            $invoice->seller->singleLine(),
            $invoice->seller->registration_number !== null
                ? 'Reg. '.$invoice->seller->registration_number
                : null,
        ]);

        $detailBottom = $page->block($page->document()->width() / 2, $y, 'Details', [
            'Order '.$invoice->order->number,
            'Issued '.$invoice->issued_at->format('j M Y'),
            'VAT on commission at '.$invoice->vat_rate_percent.'%',
        ]);

        $y = max($sellerBottom, $detailBottom) + 24;

        $y = $page->figures($y, 'Charged by MonaFindAuto', [
            [$this->commissionLine($invoice), $invoice->commission_ngwee],
            ['VAT on commission at '.$invoice->vat_rate_percent.'%', $invoice->vat_ngwee],
            ['Total invoiced', $invoice->total_ngwee, true],
        ]);

        /* Shown because they were deducted, not because they are invoiced here. */
        if ($invoice->addon_fee_ngwee->isPositive() || $invoice->referral_fee_ngwee->isPositive()) {
            $y = $page->figures($y, 'Other deductions from your payout (not invoiced)', [
                ['Add-on fee', $invoice->addon_fee_ngwee],
                ['Referral fee', $invoice->referral_fee_ngwee],
                ['Total deducted', $invoice->addon_fee_ngwee->plus($invoice->referral_fee_ngwee), true],
            ]);
        }

        $y += 6;
        $y = $page->heading($y, 'Goods value this commission was charged on: '.$invoice->goods_ngwee->format());
        $y += 12;

        $page->note($y, [
            'Product prices on MonaFindAuto are VAT-inclusive. VAT on the goods sold is the seller\'s own '
                .'responsibility to account for to ZRA; MonaFindAuto neither collects nor remits it.',
            'This invoice covers MonaFindAuto\'s commission and the VAT charged on that commission only. '
                .'It is not an invoice for the order, and the order total does not appear on it.',
            'Commission was charged on the goods value shown above. Delivery fees are not commissionable '
                .'and pass to the seller in full.',
            'Invoice '.$invoice->number.' relates to order '.$invoice->order->number.'.',
        ]);

        $page->footer('Commission invoice '.$invoice->number);

        return $page->render();
    }

    public function filename(CommissionInvoice $invoice): string
    {
        return $invoice->number.'.pdf';
    }

    /**
     * How the commission was worked out, in the terms the order settled under.
     *
     * Read off the invoice's own snapshot rather than the seller's live
     * policy, so an invoice reprinted years later still explains the figure
     * printed beside it.
     */
    private function commissionLine(CommissionInvoice $invoice): string
    {
        $snapshot = $invoice->monetisation();

        return $snapshot->commissionType === 'flat'
            ? 'Commission ('.$snapshot->commissionFlat->format().' per order)'
            : 'Commission at '.$snapshot->commissionPercent.'% of goods';
    }
}
