<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Jobs\GenerateMonthlyStatements;
use App\Modules\Finance\Models\SellerStatement;
use App\Modules\Finance\Services\CommissionInvoiceDocumentService;
use App\Modules\Finance\Services\SellerStatementService;
use App\Modules\Finance\Services\StatementDocumentService;
use App\Modules\Ledger\Models\CommissionInvoice;
use App\Modules\Sellers\Models\Seller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Statements and commission invoices, from the platform's side.
 *
 * Finance sees every seller's; a seller sees their own on their own screen.
 * The two read the same closed rows, so a support conversation about "the
 * figure on my statement" is about one document rather than two renderings
 * that might differ.
 *
 * Closing a month by hand is here because the monthly job can fail for one
 * seller without failing for the rest, and somebody has to be able to finish
 * the job. Rebuilding a month that already closed is a separate, audited act
 * — see SellerStatementService::regenerate() — because a seller may be
 * holding the superseded copy.
 */
class StatementController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly SellerStatementService $statements,
        private readonly StatementDocumentService $documents,
        private readonly CommissionInvoiceDocumentService $invoiceDocuments,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('finance');

        $filters = $request->validate([
            'period' => ['nullable', 'string', 'max:7'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $statements = SellerStatement::query()
            ->with('seller')
            ->when(
                ($filters['period'] ?? null) !== null,
                fn ($query) => $query
                    ->where('period_year', (int) substr((string) $filters['period'], 0, 4))
                    ->where('period_month', (int) substr((string) $filters['period'], 5, 2)),
            )
            ->when(
                ($filters['search'] ?? null) !== null,
                fn ($query) => $query->whereHas(
                    'seller',
                    fn ($sellers) => $sellers->where('business_name', 'like', '%'.$filters['search'].'%'),
                ),
            )
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('seller_id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (SellerStatement $statement): array => [
                'id' => $statement->getKey(),
                'seller' => $statement->seller->business_name,
                'period' => $statement->period(),
                'periodLabel' => $statement->periodLabel(),
                'orderCount' => $statement->order_count,
                'salesNgwee' => $statement->sales_ngwee->ngwee,
                'commissionNgwee' => $statement->commission_ngwee->ngwee,
                'vatNgwee' => $statement->vat_on_commission_ngwee->ngwee,
                'payoutsNgwee' => $statement->payouts_ngwee->ngwee,
                'closingPayableNgwee' => $statement->closing_payable_ngwee->ngwee,
                'pdfUrl' => route('admin.finance.statements.download', ['statement' => $statement, 'format' => 'pdf']),
                'csvUrl' => route('admin.finance.statements.download', ['statement' => $statement, 'format' => 'csv']),
            ]);

        return Inertia::render('admin/finance/statements/Index', [
            'statements' => $statements,
            'filters' => [
                'period' => $filters['period'] ?? null,
                'search' => $filters['search'] ?? null,
            ],
            'invoices' => CommissionInvoice::query()
                ->with('seller')
                ->latest('issued_at')
                ->limit(25)
                ->get()
                ->map(fn (CommissionInvoice $invoice): array => [
                    'number' => $invoice->number,
                    'seller' => $invoice->seller->business_name,
                    'issuedAt' => $invoice->issued_at->toDateString(),
                    'commissionNgwee' => $invoice->commission_ngwee->ngwee,
                    'vatNgwee' => $invoice->vat_ngwee->ngwee,
                    'totalNgwee' => $invoice->total_ngwee->ngwee,
                    'vatRatePercent' => $invoice->vat_rate_percent,
                    'pdfUrl' => route('admin.finance.statements.invoice', $invoice),
                ]),
        ]);
    }

    /**
     * Close a month across the platform, by hand.
     *
     * Queued rather than run inline: it walks every seller who traded and
     * writes a row each, which is not work to do inside a web request.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('finance');

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        GenerateMonthlyStatements::dispatch($validated['month']);

        return back()->with('success', __('Closing :month for every seller who traded. Statements appear as they finish.', [
            'month' => CarbonImmutable::parse($validated['month'])->format('F Y'),
        ]));
    }

    /**
     * Rebuild one seller's closed month against the ledger as it stands now.
     *
     * Platform administrators only, and audited by the service. A seller may
     * be holding the superseded copy, so this is never a routine refresh.
     */
    public function regenerate(Request $request, Seller $seller): RedirectResponse
    {
        Gate::authorize('admin-only');

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->statements->regenerate(
            $seller,
            CarbonImmutable::parse($validated['month']),
            $validated['reason'],
        );

        return back()->with('success', __('Statement rebuilt from the ledger.'));
    }

    public function download(Request $request, SellerStatement $statement, string $format): StreamedResponse
    {
        Gate::authorize('finance');

        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $contents = $format === 'pdf'
            ? $this->documents->pdf($statement)
            : $this->documents->csv($statement);

        return $this->stream(
            $contents,
            $this->documents->filename($statement, $format),
            $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
        );
    }

    public function invoice(Request $request, CommissionInvoice $invoice): StreamedResponse
    {
        Gate::authorize('finance');

        return $this->stream(
            $this->invoiceDocuments->pdf($invoice),
            $this->invoiceDocuments->filename($invoice),
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
