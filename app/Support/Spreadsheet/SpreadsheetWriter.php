<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use RuntimeException;
use ZipArchive;

/**
 * Writes the bulk-stock template as CSV or XLSX.
 *
 * Hand-rolled rather than pulled from a package, because the platform has one
 * spreadsheet to write and one to read, both a flat grid of strings and
 * numbers. A general-purpose spreadsheet library would be several megabytes
 * of dependency for a four-column template — and adding one is not a decision
 * this module gets to make on its own.
 *
 * The XLSX produced is the minimum a real spreadsheet will open: a workbook,
 * one worksheet with its cells written as inline strings, and the two
 * relationship files that tie them together. No shared-string table, no
 * styles — Excel, LibreOffice and Sheets all accept that, and the file stays
 * something a person can unzip and read.
 */
final class SpreadsheetWriter
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    public function __construct(
        private readonly array $headers,
        private readonly array $rows = [],
        private readonly string $sheetName = 'Stock',
    ) {}

    /**
     * A CSV, with a UTF-8 byte-order mark.
     *
     * The BOM is there for Excel on Windows, which otherwise reads a plain
     * UTF-8 CSV as the local codepage and mangles anything outside ASCII.
     */
    public function toCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a buffer to write the CSV.');
        }

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, $this->headers, ',', '"', '\\');

        foreach ($this->rows as $row) {
            fputcsv($handle, array_map(static fn (string|int|null $cell): string => (string) $cell, $row), ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * An XLSX workbook.
     */
    public function toXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mfa-xlsx-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the workbook.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open a temporary workbook for writing.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->packageRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet());
        $zip->close();

        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private function packageRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($this->sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }

    private function worksheet(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $xml .= $this->row(1, $this->headers);

        foreach ($this->rows as $index => $row) {
            $xml .= $this->row($index + 2, array_map(
                static fn (string|int|null $cell): string => (string) $cell,
                $row,
            ));
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function row(int $number, array $cells): string
    {
        $xml = '<row r="'.$number.'">';

        foreach (array_values($cells) as $column => $value) {
            $reference = CellReference::forColumn($column).$number;

            /*
             * Numbers go in as numbers so a seller can sum a column; anything
             * else goes in as an inline string, which keeps a SKU like
             * "01-4400" from being read as the number 14400.
             */
            $xml .= is_numeric($value) && ! str_starts_with($value, '0')
                ? '<c r="'.$reference.'"><v>'.$this->escape($value).'</v></c>'
                : '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape($value).'</t></is></c>';
        }

        return $xml.'</row>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
