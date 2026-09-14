<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Enums\FinanceExport;
use App\Modules\Finance\Services\FinanceExportService;
use App\Modules\Finance\Support\MetricWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files the client's accountant closes a period from.
 *
 * There is no accounting integration behind MonaFind and none planned — the
 * brief removed Odoo and did not replace it — so these downloads are the
 * hand-off rather than a convenience.
 *
 * Every export is a file of names, addresses and money movements leaving the
 * platform, which is why the whole screen sits behind the finance gate and
 * why each download writes an audit row naming the file and the window. Who
 * took the ledger off the platform, and when, is exactly the sort of thing
 * that has to be answerable later.
 */
class FinanceExportController extends Controller
{
    public function __construct(private readonly FinanceExportService $exports) {}

    public function index(Request $request): Response
    {
        Gate::authorize('finance');

        return Inertia::render('admin/finance/exports/Index', [
            'exports' => FinanceExport::options(),
            /* Named `period`, not `window`: the page needs the browser's own window. */
            'period' => MetricWindow::fromStrings(
                $request->query('from') === null ? null : (string) $request->query('from'),
                $request->query('to') === null ? null : (string) $request->query('to'),
            )->toArray(),
        ]);
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        Gate::authorize('finance');

        $type = FinanceExport::tryFrom($export) ?? abort(404);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);

        $window = MetricWindow::fromStrings($validated['from'] ?? null, $validated['to'] ?? null);
        $format = $validated['format'] ?? 'csv';

        $contents = $this->exports->build($type, $window, $format);
        $filename = $this->exports->filename($type, $window, $format);

        audit(
            $request->user(),
            'finance.export.downloaded',
            null,
            null,
            ['export' => $type->value, 'format' => $format, ...$window->toArray()],
            'Finance export taken off the platform.',
        );

        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            $filename,
            ['Content-Type' => $this->contentType($format)],
        );
    }

    private function contentType(string $format): string
    {
        return $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8';
    }
}
