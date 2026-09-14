<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockImportStatus;
use App\Modules\Inventory\Exceptions\StockFileUnreadable;
use App\Modules\Inventory\Exceptions\StockImportNotApplicable;
use App\Modules\Inventory\Models\StockImportBatch;
use App\Modules\Inventory\Services\StockImportService;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Spreadsheet\SpreadsheetReader;
use App\Support\Spreadsheet\SpreadsheetWriter;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');

    $this->imports = app(StockImportService::class);
    $this->seller = Seller::factory()->create();

    $this->product = Product::factory()->ofSeller($this->seller)->create();
    $this->variant = $this->product->variants()->first();
    $this->variant->forceFill([
        'sku' => 'ALT-2KD-001',
        'quantity' => 4,
        'price' => Money::ofKwacha('1200.00'),
    ])->save();
});

/**
 * A CSV upload, built the way a seller's spreadsheet would write one.
 *
 * @param  array<int, array<int, string>>  $rows
 */
function stockCsv(array $rows, array $headers = ['SKU', 'Quantity', 'Price (K)']): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'mfa-stock-').'.csv';

    file_put_contents($path, (new SpreadsheetWriter($headers, $rows))->toCsv());

    return new UploadedFile($path, 'stock.csv', 'text/csv', null, true);
}

it('builds a template pre-filled with what the seller has', function (): void {
    $csv = $this->imports->template($this->seller, 'csv');

    expect($csv['filename'])->toEndWith('.csv')
        ->and($csv['contents'])->toContain('ALT-2KD-001')
        ->and($csv['contents'])->toContain('1200.00');
});

it('builds an XLSX a spreadsheet can actually open', function (): void {
    $xlsx = $this->imports->template($this->seller, 'xlsx');

    $path = tempnam(sys_get_temp_dir(), 'mfa-template-').'.xlsx';
    file_put_contents($path, $xlsx['contents']);

    $rows = SpreadsheetReader::rows($path, 'xlsx');

    expect($rows[0])->toBe(['SKU', 'Quantity', 'Price (K)'])
        ->and($rows[1][0])->toBe('ALT-2KD-001')
        ->and($rows[1][1])->toBe('4');

    unlink($path);
});

it('reports bad rows by line number and applies the good ones', function (): void {
    $second = Product::factory()->ofSeller($this->seller)->create();
    $second->variants()->first()->forceFill(['sku' => 'BRK-PAD-77', 'quantity' => 1])->save();

    $file = stockCsv([
        ['ALT-2KD-001', '9', '1350.00'],
        ['NOT-A-SKU', '3', '500.00'],
        ['BRK-PAD-77', 'plenty', '500.00'],
        ['', '2', '100.00'],
    ]);

    $batch = $this->imports->stage($this->seller, $file);

    expect($batch->total_rows)->toBe(4)
        ->and($batch->valid_rows)->toBe(1)
        ->and($batch->invalid_rows)->toBe(3);

    $problems = collect($batch->invalidRows())->keyBy('line');

    expect($problems[3]['errors'][0])->toContain('No listing in your shop has the SKU "NOT-A-SKU"')
        ->and($problems[4]['errors'][0])->toContain('"plenty" is not a whole number')
        ->and($problems[5]['errors'][0])->toContain('The SKU is missing');

    /* Nothing has moved yet: staging is a report, not a write. */
    expect($this->variant->refresh()->quantity)->toBe(4);

    $this->imports->apply($batch);

    expect($this->variant->refresh()->quantity)->toBe(9)
        ->and($this->variant->price->ngwee)->toBe(135000)
        ->and($batch->refresh()->applied_rows)->toBe(1)
        ->and($batch->status)->toBe(StockImportStatus::Applied);
});

it('catches the same SKU appearing twice in one file', function (): void {
    $file = stockCsv([
        ['ALT-2KD-001', '9', ''],
        ['ALT-2KD-001', '3', ''],
    ]);

    $batch = $this->imports->stage($this->seller, $file);

    expect($batch->invalid_rows)->toBe(1)
        ->and($batch->invalidRows()[0]['errors'][0])->toContain('already on line 2');
});

it('leaves a price alone when the column is blank', function (): void {
    $file = stockCsv([['ALT-2KD-001', '7', '']]);

    $this->imports->apply($this->imports->stage($this->seller, $file));

    expect($this->variant->refresh()->quantity)->toBe(7)
        ->and($this->variant->price->ngwee)->toBe(120000);
});

it('refuses a price it cannot read rather than guessing at it', function (): void {
    $file = stockCsv([['ALT-2KD-001', '7', 'about a thousand']]);

    $batch = $this->imports->stage($this->seller, $file);

    expect($batch->valid_rows)->toBe(0)
        ->and($batch->invalidRows()[0]['errors'][0])->toContain('is not a price MonaFind can read');
});

it('refuses a file whose headings it does not recognise', function (): void {
    $file = stockCsv([['ALT-2KD-001', '9']], headers: ['Part', 'Amount']);

    expect(fn () => $this->imports->stage($this->seller, $file))
        ->toThrow(StockFileUnreadable::class, 'missing the sku and quantity');
});

it('refuses a file with a heading row and nothing under it', function (): void {
    expect(fn () => $this->imports->stage($this->seller, stockCsv([])))
        ->toThrow(StockFileUnreadable::class, 'no stock rows');
});

it('will not touch another shop stock however the SKU is spelt', function (): void {
    $other = Seller::factory()->create();
    $theirs = Product::factory()->ofSeller($other)->create();
    $theirs->variants()->first()->forceFill(['sku' => 'THEIRS-1', 'quantity' => 3])->save();

    $file = stockCsv([['THEIRS-1', '99', '']]);

    $batch = $this->imports->stage($this->seller, $file);

    expect($batch->valid_rows)->toBe(0)
        ->and($theirs->variants()->first()->quantity)->toBe(3);
});

it('refuses to apply the same batch twice', function (): void {
    $batch = $this->imports->stage($this->seller, stockCsv([['ALT-2KD-001', '9', '']]));

    $this->imports->apply($batch);

    expect(fn () => $this->imports->apply($batch))
        ->toThrow(StockImportNotApplicable::class);
});

it('deletes the seller price list once the batch is finished', function (): void {
    $batch = $this->imports->stage($this->seller, stockCsv([['ALT-2KD-001', '9', '']]));

    expect($batch->stored_path)->not->toBeNull();
    Storage::disk('local')->assertExists($batch->stored_path);

    $stored = $batch->stored_path;
    $this->imports->apply($batch);

    Storage::disk('local')->assertMissing($stored);
    expect($batch->refresh()->stored_path)->toBeNull();
});

it('records a bulk price change in the audit trail', function (): void {
    $this->imports->apply($this->imports->stage($this->seller, stockCsv([['ALT-2KD-001', '9', '1350.00']])));

    $this->assertDatabaseHas('audit_logs', ['action' => 'stock.price_updated']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'stock.bulk_import_applied']);
});

it('reads an XLSX that leaves its empty cells out', function (): void {
    /* Column B blank on the second row: XLSX omits the cell entirely. */
    $writer = new SpreadsheetWriter(
        ['SKU', 'Quantity', 'Price (K)'],
        [['ALT-2KD-001', '', '1350.00']],
    );

    $path = tempnam(sys_get_temp_dir(), 'mfa-sparse-').'.xlsx';
    file_put_contents($path, $writer->toXlsx());

    $file = new UploadedFile(
        $path,
        'stock.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );

    $batch = $this->imports->stage($this->seller, $file);

    /* The price must still land in the price column, not shift into quantity. */
    expect($batch->invalidRows()[0]['errors'][0])->toContain('quantity is missing')
        ->and($batch->rows[0]['price_ngwee'])->toBe(135000);
});

it('refuses a file with more rows than the platform allows', function (): void {
    $rows = array_fill(0, 12, ['ALT-2KD-001', '1', '']);

    $this->seed(SettingsSeeder::class);
    settings()->set('stock.import_max_rows', 10);

    expect(fn () => $this->imports->stage($this->seller, stockCsv($rows)))
        ->toThrow(StockFileUnreadable::class, 'the limit is 10');
});

it('records a file it could not read at all', function (): void {
    $batch = $this->imports->recordFailure($this->seller, 'nonsense.csv', 'The file is missing the sku column.');

    expect($batch->status)->toBe(StockImportStatus::Failed)
        ->and(StockImportBatch::query()->count())->toBe(1);
});
