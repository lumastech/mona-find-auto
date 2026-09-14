<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\SellerStatement;
use App\Modules\Finance\Services\CommissionInvoiceDocumentService;
use App\Modules\Finance\Services\StatementDocumentService;
use App\Modules\Ledger\Models\CommissionInvoice;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A seller's own statements and the commission invoices behind them.
 *
 * Read-only, and the figures are read from the CLOSED statement row rather
 * than recomputed — a seller who downloaded October in November must get the
 * same page in March, even if an adjustment has since been backdated into
 * October. See SellerStatementService for why that is the right trade.
 *
 * Statements and invoices are shown on one screen because sellers ask about
 * them together: the statement says what was deducted, the invoices are the
 * tax documents for the commission part of it. Splitting them across two
 * screens means a seller finds one and emails support about the other.
 *
 * Every download re-checks ownership through the seller resolved from the
 * session, never through an id in the URL.
 */
class StatementController extends Controller
{
    use ResolvesCurrentSeller;

    public function __construct(
        private readonly StatementDocumentService $documents,
        private readonly CommissionInvoiceDocumentService $invoices,
    ) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        $statements = SellerStatement::query()
            ->forSeller($seller)
            ->paginate(24)
            ->through(static fn (SellerStatement $statement): array => [
                'id' => $statement->getKey(),
                'period' => $statement->period(),
                'periodLabel' => $statement->periodLabel(),
                'orderCount' => $statement->order_count,
                'salesNgwee' => $statement->sales_ngwee->ngwee,
                'deductionsNgwee' => $statement->totalDeductions()->ngwee,
                'commissionNgwee' => $statement->commission_ngwee->ngwee,
                'addonFeeNgwee' => $statement->addon_fee_ngwee->ngwee,
                'referralFeeNgwee' => $statement->referral_fee_ngwee->ngwee,
                'vatNgwee' => $statement->vat_on_commission_ngwee->ngwee,
                'refundsNgwee' => $statement->refunds_ngwee->ngwee,
                'payoutsNgwee' => $statement->payouts_ngwee->ngwee,
                'closingPayableNgwee' => $statement->closing_payable_ngwee->ngwee,
                'closingReserveNgwee' => $statement->closing_reserve_ngwee->ngwee,
                'pdfUrl' => route('seller.statements.download', ['statement' => $statement, 'format' => 'pdf']),
                'csvUrl' => route('seller.statements.download', ['statement' => $statement, 'format' => 'csv']),
            ]);

        $invoices = CommissionInvoice::query()
            ->forSeller($seller)
            ->limit(50)
            ->get()
            ->map(static fn (CommissionInvoice $invoice): array => [
                'number' => $invoice->number,
                'issuedAt' => $invoice->issued_at->toDateString(),
                'commissionNgwee' => $invoice->commission_ngwee->ngwee,
                'vatNgwee' => $invoice->vat_ngwee->ngwee,
                'totalNgwee' => $invoice->total_ngwee->ngwee,
                'vatRatePercent' => $invoice->vat_rate_percent,
                'pdfUrl' => route('seller.statements.invoice', $invoice),
            ]);

        return Inertia::render('seller/statements/Index', [
            'statements' => $statements,
            'invoices' => $invoices,
        ]);
    }

    public function download(Request $request, SellerStatement $statement, string $format): StreamedResponse
    {
        $seller = $this->currentSeller($request);

        abort_unless($statement->seller_id === $seller->getKey(), HttpResponse::HTTP_NOT_FOUND);
        abort_unless(in_array($format, ['pdf', 'csv'], true), HttpResponse::HTTP_NOT_FOUND);

        $contents = $format === 'pdf'
            ? $this->documents->pdf($statement)
            : $this->documents->csv($statement);

        return $this->stream(
            $contents,
            $this->documents->filename($statement, $format),
            $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
        );
    }

    /**
     * One commission invoice, as a numbered PDF.
     */
    public function invoice(Request $request, CommissionInvoice $invoice): StreamedResponse
    {
        $seller = $this->currentSeller($request);

        abort_unless($invoice->seller_id === $seller->getKey(), HttpResponse::HTTP_NOT_FOUND);

        return $this->stream(
            $this->invoices->pdf($invoice),
            $this->invoices->filename($invoice),
            'application/pdf',
        );
    }

    private function stream(string $contents, string $filename, string $contentType): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            $filename,
            ['Content-Type' => $contentType],
        );
    }
}
