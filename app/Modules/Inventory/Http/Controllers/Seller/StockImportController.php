<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Exceptions\StockFileUnreadable;
use App\Modules\Inventory\Http\Requests\Seller\StockImportRequest;
use App\Modules\Inventory\Http\Resources\StockImportBatchResource;
use App\Modules\Inventory\Jobs\ApplyStockImport;
use App\Modules\Inventory\Models\StockImportBatch;
use App\Modules\Inventory\Services\StockImportService;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk stock updates, in two steps: upload and read the report, then apply.
 *
 * The gap between the two is the feature. A one-step import discovers a
 * mistyped price when a buyer pays it; this one shows the seller the bad rows
 * by line number and lets them decide whether to apply the rest.
 */
class StockImportController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(private readonly StockImportService $imports) {}

    /**
     * Download the template, pre-filled with the seller's current stock.
     *
     * An empty template is a form to fill in; a filled one is a stock take to
     * correct — which is the job a parts shop actually does.
     */
    public function template(Request $request): StreamedResponse
    {
        $seller = $this->currentSeller($request);

        $format = $request->string('format')->toString() === 'csv' ? 'csv' : 'xlsx';
        $file = $this->imports->template($seller, $format);

        return response()->streamDownload(
            static function () use ($file): void {
                echo $file['contents'];
            },
            $file['filename'],
            ['Content-Type' => $file['mime']],
        );
    }

    /**
     * Upload a file and produce its report. Nothing is written to a shelf.
     *
     * @throws ValidationException when the file cannot be read at all
     */
    public function store(StockImportRequest $request): RedirectResponse
    {
        $seller = $this->currentSeller($request);
        $actor = $this->currentUser($request);
        $file = $request->file('file');

        try {
            $batch = $this->imports->stage($seller, $file, $actor);
        } catch (StockFileUnreadable $exception) {
            /*
             * Recorded even though nothing was applied: a seller who uploads
             * the wrong file three times deserves a history that shows it,
             * and so does support when they ring about it.
             */
            $this->imports->recordFailure($seller, $file->getClientOriginalName(), $exception->getMessage(), $actor);

            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return to_route('seller.stock.imports.show', $batch);
    }

    /**
     * The report: which rows are wrong, and why.
     */
    public function show(Request $request, StockImportBatch $batch): Response
    {
        Gate::authorize('view', $batch);

        return Inertia::render('seller/stock/Import', [
            'batch' => StockImportBatchResource::make($batch)->resolve($request),
        ]);
    }

    /**
     * Apply the good rows.
     *
     * Queued: two thousand rows is two thousand locked transactions, and a
     * seller watching a spinner will press the button again.
     */
    public function apply(Request $request, StockImportBatch $batch): RedirectResponse
    {
        Gate::authorize('apply', $batch);

        ApplyStockImport::dispatch($batch, $this->currentUser($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{1}Applying one row. It will be on your shelves in a moment.|[2,*]Applying :count rows. They will be on your shelves in a moment.',
                $batch->valid_rows,
                ['count' => $batch->valid_rows],
            ),
        ]);

        return to_route('seller.stock.index');
    }

    /**
     * Walk away from a report without applying it.
     */
    public function destroy(Request $request, StockImportBatch $batch): RedirectResponse
    {
        Gate::authorize('discard', $batch);

        $this->imports->discard($batch);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Upload discarded.')]);

        return to_route('seller.stock.index');
    }
}
