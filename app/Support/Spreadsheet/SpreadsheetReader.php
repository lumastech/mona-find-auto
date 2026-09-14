<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use App\Modules\Inventory\Exceptions\StockFileUnreadable;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

/**
 * Reads a bulk-stock upload back out of CSV or XLSX.
 *
 * The counterpart to SpreadsheetWriter, and hand-rolled for the same reason.
 * It reads the first worksheet as a flat grid of strings and hands back rows
 * keyed by their header, which is all this module's row validation needs.
 *
 * Two details that a naive reader gets wrong, and that a seller's real file
 * will hit within the week:
 *
 * - XLSX omits empty cells entirely, so row 5 may jump from A to D. Cells are
 *   placed by their own `r` reference rather than by the order they appear,
 *   or a blank quantity would shift every later column left by one.
 * - Text is usually in a shared-string table, so a cell holding "3" may mean
 *   "the third distinct string in this workbook" rather than the number three.
 */
final class SpreadsheetReader
{
    /**
     * The grid, as rows of trimmed strings. The first row is the heading row.
     *
     * @return array<int, array<int, string>>
     *
     * @throws StockFileUnreadable
     */
    public static function rows(string $path, string $extension): array
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => self::readCsv($path),
            'xlsx' => self::readXlsx($path),
            default => throw StockFileUnreadable::unsupportedFormat($extension),
        };
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function readCsv(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw StockFileUnreadable::corrupt('the file could not be opened for reading.');
        }

        $rows = [];

        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            /* fgetcsv gives [null] for a blank line; a blank line is not a row. */
            if ($cells === [null]) {
                continue;
            }

            $rows[] = array_map(
                static fn (?string $cell): string => trim((string) $cell),
                $cells,
            );
        }

        fclose($handle);

        if ($rows !== []) {
            /* Strip the byte-order mark Excel writes, so the first header still matches. */
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]) ?? $rows[0][0];
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function readXlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw StockFileUnreadable::corrupt('it is not a readable XLSX workbook.');
        }

        try {
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

            if ($sheet === false) {
                throw StockFileUnreadable::corrupt('the workbook has no first worksheet.');
            }

            $sharedStrings = self::sharedStrings($zip);
            $rows = self::parseSheet($sheet, $sharedStrings);
        } catch (StockFileUnreadable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StockFileUnreadable::corrupt($exception->getMessage());
        } finally {
            $zip->close();
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $document = new SimpleXMLElement($xml);
        $strings = [];

        foreach ($document->si as $item) {
            /* A run-formatted string is split across <r><t> children; join them back up. */
            $strings[] = trim(implode('', array_map(
                static fn (SimpleXMLElement $text): string => (string) $text,
                iterator_to_array($item->xpath('.//*[local-name()="t"]') ?: [], false),
            )));
        }

        return $strings;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private static function parseSheet(string $xml, array $sharedStrings): array
    {
        $document = new SimpleXMLElement($xml);
        $rows = [];

        foreach ($document->sheetData->row as $row) {
            $cells = [];
            $widest = -1;

            foreach ($row->c as $cell) {
                $column = CellReference::toColumn((string) ($cell['r'] ?? 'A'));
                $cells[$column] = self::cellValue($cell, $sharedStrings);
                $widest = max($widest, $column);
            }

            /* Fill the gaps XLSX left out, so every row is the same shape. */
            $ordered = [];

            for ($column = 0; $column <= $widest; $column++) {
                $ordered[] = $cells[$column] ?? '';
            }

            $rows[] = $ordered;
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private static function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            return trim(implode('', array_map(
                static fn (SimpleXMLElement $text): string => (string) $text,
                iterator_to_array($cell->xpath('.//*[local-name()="t"]') ?: [], false),
            )));
        }

        $value = trim((string) $cell->v);

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        return $value;
    }
}
