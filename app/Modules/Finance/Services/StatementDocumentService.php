<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\SellerStatement;
use App\Modules\Finance\Support\Pdf\FinanceStationery;
use App\Support\Money\Money;
use App\Support\Spreadsheet\SpreadsheetWriter;

/**
 * A seller's monthly statement, as a PDF to file and a CSV to work with.
 *
 * Two formats because they answer different needs and neither substitutes for
 * the other: the PDF is what a seller shows their accountant, and the CSV is
 * what that accountant pastes into a spreadsheet. Both are rendered from the
 * same closed SellerStatement row, so they can never disagree.
 *
 * The statement says what MonaFind deducted and why, split by the component
 * that earned it — commission, add-on, referral, and the VAT on the
 * commission alone. It does NOT invoice anything: the commission invoices are
 * separate numbered documents, and the note at the foot says so, because a
 * seller who treats a statement as a tax invoice will file the wrong number.
 */
class StatementDocumentService
{
    /**
     * The PDF: a month on one page.
     */
    public function pdf(SellerStatement $statement): string
    {
        $statement->loadMissing('seller');

        $page = FinanceStationery::page();
        $y = $page->masthead('Statement', $statement->periodLabel(), $statement->generated_at);

        $y += 6;
        $y = $page->block(FinanceStationery::MARGIN, $y, 'Seller', [
            $statement->seller->business_name,
            $statement->seller->singleLine(),
            $statement->seller->registration_number !== null
                ? 'Reg. '.$statement->seller->registration_number
                : null,
        ]);

        $y = $page->block($page->document()->width() / 2, $y - FinanceStationery::LINE_HEIGHT * 2, 'Period', [
            $statement->period_start->format('j M Y').' to '.$statement->period_end->format('j M Y'),
            $statement->order_count.' order'.($statement->order_count === 1 ? '' : 's'),
        ]);

        $y += 20;

        $y = $page->figures($y, 'Sales', [
            ['Gross sales (VAT-inclusive)', $statement->sales_ngwee],
            ['Refunded to buyers', $statement->refunds_ngwee],
            ['Net sales', $statement->sales_ngwee->minus($statement->refunds_ngwee), true],
        ]);

        $y = $page->figures($y, 'MonaFind charges', [
            ['Commission', $statement->commission_ngwee],
            ['Add-on fee', $statement->addon_fee_ngwee],
            ['Referral fee', $statement->referral_fee_ngwee],
            ['VAT on commission', $statement->vat_on_commission_ngwee],
            ['Total deducted', $statement->totalDeductions(), true],
        ]);

        $y = $page->figures($y, 'Settlement', [
            ['Earned this period', $statement->netEarnings()],
            ['Paid out this period', $statement->payouts_ngwee],
            ['Reserve withheld', $statement->reserve_withheld_ngwee],
            ['Reserve released', $statement->reserve_released_ngwee],
        ]);

        $y = $page->figures($y, 'Closing position', [
            ['Payable to you', $statement->closing_payable_ngwee, true],
            ['Reserve held', $statement->closing_reserve_ngwee],
        ]);

        $y += 10;

        $page->note($y, [
            'Product prices on MonaFindAuto are VAT-inclusive. VAT on the goods you sold is your own '
                .'responsibility to account for; MonaFindAuto does not collect or remit it on your behalf.',
            'MonaFindAuto invoices you for its commission only. The VAT shown above is charged on that '
                .'commission and is itemised on the numbered commission invoices for the period.',
            'This statement is not a tax invoice. It is a summary of the movements on your account, '
                .'taken from the platform ledger when the period closed.',
        ]);

        $page->footer($statement->seller->business_name.' · statement for '.$statement->periodLabel());

        return $page->render();
    }

    /**
     * The CSV: the same figures, one per row, for a spreadsheet.
     *
     * Amounts are written in kwacha with two decimals rather than as ngwee
     * integers, because this file is opened by people rather than parsed by
     * machines, and a column reading 40000000 helps nobody.
     */
    public function csv(SellerStatement $statement): string
    {
        $statement->loadMissing('seller');

        return (new SpreadsheetWriter(
            headers: ['Section', 'Item', 'Amount (ZMW)'],
            rows: $this->rows($statement),
            sheetName: 'Statement',
        ))->toCsv();
    }

    public function filename(SellerStatement $statement, string $extension): string
    {
        return sprintf(
            'statement-%s-%s.%s',
            $statement->seller->slug ?? $statement->seller_id,
            $statement->period(),
            $extension,
        );
    }

    /**
     * @return array<int, array<int, string|int|null>>
     */
    private function rows(SellerStatement $statement): array
    {
        $amount = static fn (Money $money): string => $money->toDecimalString();

        return [
            ['Period', 'Start', $statement->period_start->toDateString()],
            ['Period', 'End', $statement->period_end->toDateString()],
            ['Period', 'Orders', (string) $statement->order_count],

            ['Sales', 'Gross sales (VAT-inclusive)', $amount($statement->sales_ngwee)],
            ['Sales', 'Refunded to buyers', $amount($statement->refunds_ngwee)],
            ['Sales', 'Net sales', $amount($statement->sales_ngwee->minus($statement->refunds_ngwee))],

            ['MonaFind charges', 'Commission', $amount($statement->commission_ngwee)],
            ['MonaFind charges', 'Add-on fee', $amount($statement->addon_fee_ngwee)],
            ['MonaFind charges', 'Referral fee', $amount($statement->referral_fee_ngwee)],
            ['MonaFind charges', 'VAT on commission', $amount($statement->vat_on_commission_ngwee)],
            ['MonaFind charges', 'Total deducted', $amount($statement->totalDeductions())],

            ['Settlement', 'Earned this period', $amount($statement->netEarnings())],
            ['Settlement', 'Paid out this period', $amount($statement->payouts_ngwee)],
            ['Settlement', 'Reserve withheld', $amount($statement->reserve_withheld_ngwee)],
            ['Settlement', 'Reserve released', $amount($statement->reserve_released_ngwee)],

            ['Closing position', 'Payable to you', $amount($statement->closing_payable_ngwee)],
            ['Closing position', 'Reserve held', $amount($statement->closing_reserve_ngwee)],
        ];
    }
}
