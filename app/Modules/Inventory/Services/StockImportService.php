<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\StockImportStatus;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Inventory\Exceptions\StockFileUnreadable;
use App\Modules\Inventory\Exceptions\StockImportNotApplicable;
use App\Modules\Inventory\Models\StockImportBatch;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Money\MoneyException;
use App\Support\Spreadsheet\SpreadsheetReader;
use App\Support\Spreadsheet\SpreadsheetWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk stock updates from a spreadsheet.
 *
 * Two steps, deliberately. A seller uploads, reads a report saying exactly
 * which rows are wrong and why, and only then applies the good ones. A
 * one-step import discovers a mistyped price when a buyer pays it.
 *
 * Good rows are applied even when bad ones exist. A shop with four hundred
 * lines and two typos should not have to fix the file to update the other
 * three hundred and ninety-eight — the two are reported, and reported by line
 * number so they can be found.
 *
 * Nothing here talks to an external system. A seller's stock lives on
 * MonaFind; there is no ERP to reconcile against.
 */
class StockImportService
{
    /** The template's columns, in order. These are what the reader looks for. */
    public const COLUMNS = ['sku', 'quantity', 'price'];

    /** Where an uploaded file waits between the report and the apply. */
    public const DISK = 'local';

    public const DIRECTORY = 'stock-imports';

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * The template, pre-filled with what the seller currently has.
     *
     * An empty template is a form; a filled one is a stock take. A seller
     * downloads this, walks the shelves with it, corrects the numbers that
     * are wrong and uploads it back — which is the workflow a parts shop
     * actually runs, rather than typing four hundred SKUs from memory.
     *
     * @return array{filename: string, contents: string, mime: string}
     */
    public function template(Seller $seller, string $format = 'xlsx'): array
    {
        $rows = ProductVariant::query()
            ->whereIn('product_id', $this->sellerProductIds($seller))
            ->with('product:id,name')
            ->orderBy('product_id')
            ->orderBy('position')
            ->get()
            ->map(static fn (ProductVariant $variant): array => [
                $variant->sku,
                (string) $variant->quantity,
                $variant->price->toDecimalString(),
            ])
            ->all();

        $writer = new SpreadsheetWriter(['SKU', 'Quantity', 'Price (K)'], $rows);
        $filename = sprintf('monafind-stock-%s-%s', $seller->slug, now()->format('Y-m-d'));

        return $format === 'csv'
            ? ['filename' => $filename.'.csv', 'contents' => $writer->toCsv(), 'mime' => 'text/csv']
            : [
                'filename' => $filename.'.xlsx',
                'contents' => $writer->toXlsx(),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
    }

    /**
     * Read an upload, check every row, and store the report.
     *
     * Nothing is written to a shelf here. The batch that comes back is the
     * seller's report, and applying it is a separate decision.
     *
     * @throws StockFileUnreadable when the file is not a spreadsheet at all
     */
    public function stage(Seller $seller, UploadedFile $file, ?User $actor = null): StockImportBatch
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $grid = SpreadsheetReader::rows($file->getRealPath(), $extension);

        $headerRow = array_shift($grid);

        if ($headerRow === null) {
            throw StockFileUnreadable::empty();
        }

        $columns = $this->mapColumns($headerRow);
        $limit = settings()->integer('stock.import_max_rows', 2000);

        if (count($grid) > $limit) {
            throw StockFileUnreadable::tooManyRows(count($grid), $limit);
        }

        if ($grid === []) {
            throw StockFileUnreadable::empty();
        }

        $rows = $this->validateRows($seller, $grid, $columns);
        $valid = count(array_filter($rows, static fn (array $row): bool => $row['valid']));

        $path = $file->store(self::DIRECTORY, self::DISK);

        return StockImportBatch::query()->create([
            'seller_id' => $seller->getKey(),
            'actor_id' => $actor?->getKey(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => is_string($path) ? $path : null,
            'status' => StockImportStatus::AwaitingConfirmation,
            'total_rows' => count($rows),
            'valid_rows' => $valid,
            'invalid_rows' => count($rows) - $valid,
            'rows' => $rows,
        ]);
    }

    /**
     * Write the good rows to the shelves.
     *
     * Quantities go through the stock ledger like every other change, so a
     * bulk upload leaves the same auditable trail as a seller typing one
     * number — and triggers the same low-stock and back-in-stock reactions.
     *
     * Prices do not: a price is not stock, so it is written straight onto the
     * variant with an audit row of its own.
     *
     * @throws StockImportNotApplicable
     */
    public function apply(StockImportBatch $batch, ?User $actor = null): StockImportBatch
    {
        if (! $batch->status->isApplicable()) {
            throw StockImportNotApplicable::inStatus($batch);
        }

        $batch->forceFill(['status' => StockImportStatus::Applying])->save();

        $applied = 0;

        foreach ($batch->validRows() as $row) {
            $variant = ProductVariant::query()
                ->whereIn('product_id', $this->sellerProductIds($batch->seller))
                ->where('sku', $row['sku'])
                ->first();

            if ($variant === null) {
                /* The listing was deleted between the report and the apply. */
                continue;
            }

            $this->applyRow($variant, $row, $actor);

            $applied++;
        }

        $batch->forceFill([
            'status' => StockImportStatus::Applied,
            'applied_rows' => $applied,
            'applied_at' => now(),
        ])->save();

        $this->discardFile($batch);

        audit(
            $actor,
            'stock.bulk_import_applied',
            $batch,
            null,
            ['applied_rows' => $applied, 'invalid_rows' => $batch->invalid_rows],
            'Bulk stock upload applied.',
        );

        return $batch;
    }

    /**
     * The seller looked at the report and walked away.
     */
    public function discard(StockImportBatch $batch): StockImportBatch
    {
        $batch->forceFill(['status' => StockImportStatus::Discarded])->save();

        $this->discardFile($batch);

        return $batch;
    }

    /**
     * Record that the file itself could not be read.
     */
    public function recordFailure(Seller $seller, string $filename, string $reason, ?User $actor = null): StockImportBatch
    {
        return StockImportBatch::query()->create([
            'seller_id' => $seller->getKey(),
            'actor_id' => $actor?->getKey(),
            'original_filename' => $filename,
            'status' => StockImportStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * @param  array{line: int, sku: string|null, quantity: int|null, price_ngwee: int|null, errors: array<int, string>, valid: bool}  $row
     */
    private function applyRow(ProductVariant $variant, array $row, ?User $actor): void
    {
        DB::transaction(function () use ($variant, $row, $actor): void {
            if ($row['price_ngwee'] !== null && $row['price_ngwee'] !== $variant->price->ngwee) {
                $before = $variant->price->ngwee;

                $variant->forceFill(['price' => Money::ofNgwee($row['price_ngwee'])])->save();

                audit(
                    $actor,
                    'stock.price_updated',
                    $variant,
                    ['price' => $before],
                    ['price' => $row['price_ngwee']],
                    'Price changed by bulk stock upload.',
                );
            }
        });

        if ($row['quantity'] !== null) {
            $this->ledger->setQuantity(
                $variant,
                $row['quantity'],
                StockMovementReason::BulkImport,
                $actor,
                'Bulk stock upload.',
            );
        }
    }

    /**
     * Check every row, and say what is wrong with the ones that are.
     *
     * A row can collect more than one complaint, because a seller fixing a
     * file wants all of them at once rather than one per upload.
     *
     * @param  array<int, array<int, string>>  $grid
     * @param  array<string, int>  $columns
     * @return array<int, array{line: int, sku: string|null, quantity: int|null, price_ngwee: int|null, errors: array<int, string>, valid: bool}>
     */
    private function validateRows(Seller $seller, array $grid, array $columns): array
    {
        $skus = ProductVariant::query()
            ->whereIn('product_id', $this->sellerProductIds($seller))
            ->pluck('sku')
            ->flip();

        $seen = [];
        $rows = [];

        foreach ($grid as $index => $cells) {
            /* +2: the heading row, and spreadsheets counting from one. */
            $line = $index + 2;
            $errors = [];

            $sku = trim($cells[$columns['sku']] ?? '');
            $rawQuantity = trim($cells[$columns['quantity']] ?? '');
            $rawPrice = trim($cells[$columns['price']] ?? '');

            if ($sku === '' && $rawQuantity === '' && $rawPrice === '') {
                /* A trailing blank line a spreadsheet left behind is not an error. */
                continue;
            }

            if ($sku === '') {
                $errors[] = 'The SKU is missing.';
            } elseif (! $skus->has($sku)) {
                $errors[] = sprintf('No listing in your shop has the SKU "%s".', $sku);
            } elseif (isset($seen[$sku])) {
                $errors[] = sprintf('The SKU "%s" is already on line %d.', $sku, $seen[$sku]);
            } else {
                $seen[$sku] = $line;
            }

            $quantity = $this->parseQuantity($rawQuantity, $errors);
            $price = $this->parsePrice($rawPrice, $errors);

            $rows[] = [
                'line' => $line,
                'sku' => $sku === '' ? null : $sku,
                'quantity' => $quantity,
                'price_ngwee' => $price?->ngwee,
                'errors' => $errors,
                'valid' => $errors === [],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function parseQuantity(string $raw, array &$errors): ?int
    {
        if ($raw === '') {
            $errors[] = 'The quantity is missing.';

            return null;
        }

        /* A spreadsheet hands back "3.0" for a whole number it stored as a float. */
        $normalised = (string) preg_replace('/\.0+$/', '', $raw);

        if (! preg_match('/^\d+$/', $normalised)) {
            $errors[] = sprintf('"%s" is not a whole number of items.', $raw);

            return null;
        }

        return (int) $normalised;
    }

    /**
     * A blank price means "leave the price alone", which is what a stock take
     * usually means.
     *
     * @param  array<int, string>  $errors
     */
    private function parsePrice(string $raw, array &$errors): ?Money
    {
        if ($raw === '') {
            return null;
        }

        try {
            $price = Money::ofKwacha($raw);
        } catch (MoneyException) {
            $errors[] = sprintf('"%s" is not a price MonaFind can read. Use a figure like 1250.50.', $raw);

            return null;
        }

        if (! $price->isPositive()) {
            $errors[] = 'The price has to be above zero.';

            return null;
        }

        return $price;
    }

    /**
     * Find each template column, whatever the seller renamed the heading to.
     *
     * Matched on the first word rather than the whole heading, so "Price (K)"
     * and a seller's "Price ZMW" both land on the same column.
     *
     * @param  array<int, string>  $headerRow
     * @return array<string, int>
     *
     * @throws StockFileUnreadable
     */
    private function mapColumns(array $headerRow): array
    {
        $columns = [];

        foreach ($headerRow as $index => $heading) {
            $key = strtolower(trim(preg_replace('/[^A-Za-z]+.*$/', '', trim($heading)) ?: ''));

            if (in_array($key, self::COLUMNS, true) && ! isset($columns[$key])) {
                $columns[$key] = $index;
            }
        }

        $missing = array_values(array_diff(self::COLUMNS, array_keys($columns)));

        if (in_array('sku', $missing, true) || in_array('quantity', $missing, true)) {
            throw StockFileUnreadable::missingHeaders(array_intersect($missing, ['sku', 'quantity']));
        }

        /* Price is optional: a pure stock take need not carry one. */
        $columns['price'] ??= PHP_INT_MAX;

        return $columns;
    }

    /**
     * @return Builder<Product>
     */
    private function sellerProductIds(Seller $seller): Builder
    {
        return Product::query()
            ->select('id')
            ->where('seller_id', $seller->getKey())
            ->whereNot('status', ListingStatus::Archived);
    }

    private function discardFile(StockImportBatch $batch): void
    {
        if ($batch->stored_path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($batch->stored_path);

        $batch->forceFill(['stored_path' => null])->save();
    }
}
