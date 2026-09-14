<?php

declare(strict_types=1);

use App\Modules\Inventory\Exceptions\StockFileUnreadable;
use App\Support\Spreadsheet\CellReference;
use App\Support\Spreadsheet\SpreadsheetReader;
use App\Support\Spreadsheet\SpreadsheetWriter;

/**
 * Write a workbook to a temporary file and hand back its path.
 */
function tempWorkbook(string $contents, string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'mfa-sheet-').'.'.$extension;

    file_put_contents($path, $contents);

    return $path;
}

it('converts column indexes to letters and back', function (): void {
    expect(CellReference::forColumn(0))->toBe('A')
        ->and(CellReference::forColumn(25))->toBe('Z')
        /* The one everybody gets wrong: base-26 with no zero. */
        ->and(CellReference::forColumn(26))->toBe('AA')
        ->and(CellReference::forColumn(27))->toBe('AB')
        ->and(CellReference::forColumn(51))->toBe('AZ')
        ->and(CellReference::forColumn(52))->toBe('BA');

    expect(CellReference::toColumn('A1'))->toBe(0)
        ->and(CellReference::toColumn('Z9'))->toBe(25)
        ->and(CellReference::toColumn('AA10'))->toBe(26)
        ->and(CellReference::toColumn('BA1'))->toBe(52);
});

it('round-trips a grid through CSV', function (): void {
    $writer = new SpreadsheetWriter(['SKU', 'Quantity'], [['ALT-1', '4'], ['BRK-2', '0']]);

    $rows = SpreadsheetReader::rows(tempWorkbook($writer->toCsv(), 'csv'), 'csv');

    expect($rows)->toBe([['SKU', 'Quantity'], ['ALT-1', '4'], ['BRK-2', '0']]);
});

it('round-trips a grid through XLSX', function (): void {
    $writer = new SpreadsheetWriter(['SKU', 'Quantity'], [['ALT-1', '4'], ['BRK-2', '0']]);

    $rows = SpreadsheetReader::rows(tempWorkbook($writer->toXlsx(), 'xlsx'), 'xlsx');

    expect($rows)->toBe([['SKU', 'Quantity'], ['ALT-1', '4'], ['BRK-2', '0']]);
});

it('does not let Excel read a leading-zero SKU as a number', function (): void {
    $writer = new SpreadsheetWriter(['SKU'], [['01-4400']]);

    $rows = SpreadsheetReader::rows(tempWorkbook($writer->toXlsx(), 'xlsx'), 'xlsx');

    expect($rows[1][0])->toBe('01-4400');
});

it('strips the byte-order mark Excel writes on a CSV', function (): void {
    $csv = (new SpreadsheetWriter(['SKU'], [['ALT-1']]))->toCsv();

    expect($csv)->toStartWith("\xEF\xBB\xBF");

    $rows = SpreadsheetReader::rows(tempWorkbook($csv, 'csv'), 'csv');

    /* The heading has to match "sku" once the mark is gone. */
    expect($rows[0][0])->toBe('SKU');
});

it('keeps a blank cell in its own column rather than shifting the row left', function (): void {
    $writer = new SpreadsheetWriter(['SKU', 'Quantity', 'Price'], [['ALT-1', '', '99.00']]);

    $rows = SpreadsheetReader::rows(tempWorkbook($writer->toXlsx(), 'xlsx'), 'xlsx');

    expect($rows[1])->toBe(['ALT-1', '', '99.00']);
});

it('refuses a file format it cannot read', function (): void {
    expect(fn () => SpreadsheetReader::rows(tempWorkbook('anything', 'ods'), 'ods'))
        ->toThrow(StockFileUnreadable::class, 'cannot read a .ods file');
});

it('refuses a file that is not the workbook it claims to be', function (): void {
    expect(fn () => SpreadsheetReader::rows(tempWorkbook('not a zip at all', 'xlsx'), 'xlsx'))
        ->toThrow(StockFileUnreadable::class, 'not a readable XLSX workbook');
});
