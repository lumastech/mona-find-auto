<?php

declare(strict_types=1);

namespace App\Support\Pdf;

/**
 * A very small PDF writer: A4 pages, two core fonts, text and rules.
 *
 * MonaFindAuto issues two documents — a buyer's receipt and a seller's
 * packing slip — and both are a heading, a column of labelled values and a
 * table. That is the entire requirement, and it is met by a few hundred lines
 * of the PDF format's oldest and most stable corner: uncompressed content
 * streams and the two standard Helvetica faces every reader has built in.
 *
 * A rendering library would bring a browser engine, a font stack and a
 * dependency to keep patched, to lay out a table of six rows. The same
 * judgement was made for XLSX in Inventory, which builds spreadsheets with
 * ZipArchive rather than taking on a spreadsheet library. If MonaFind ever
 * needs a document with real typography in it, that is the moment to add one.
 *
 * Coordinates here are top-left origin in points, because that is how a
 * person describes a page; the conversion to PDF's bottom-left origin happens
 * once, at write time.
 */
final class PdfDocument
{
    /** A4 at 72dpi. */
    public const WIDTH = 595.28;

    public const HEIGHT = 841.89;

    public const FONT_REGULAR = 'F1';

    public const FONT_BOLD = 'F2';

    /** @var array<int, string> One content stream per page. */
    private array $pages = [];

    private string $current = '';

    private bool $started = false;

    public function __construct(
        private readonly float $width = self::WIDTH,
        private readonly float $height = self::HEIGHT,
    ) {}

    /**
     * Begin a new page. The first call starts the document.
     */
    public function addPage(): self
    {
        if ($this->started) {
            $this->pages[] = $this->current;
        }

        $this->current = '';
        $this->started = true;

        return $this;
    }

    /**
     * Draw a line of text with its baseline at $y, measured from the top.
     */
    public function text(float $x, float $y, string $text, float $size = 10.0, bool $bold = false): self
    {
        $font = $bold ? self::FONT_BOLD : self::FONT_REGULAR;

        $this->current .= sprintf(
            "BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $font,
            $size,
            $x,
            $this->height - $y,
            $this->escape($text),
        );

        return $this;
    }

    /**
     * Draw text ending at $x rather than starting there — what a column of
     * money amounts needs, since the digits have to line up on the right.
     */
    public function textRight(float $x, float $y, string $text, float $size = 10.0, bool $bold = false): self
    {
        return $this->text($x - $this->widthOf($text, $size, $bold), $y, $text, $size, $bold);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $thickness = 0.5, float $grey = 0.8): self
    {
        $this->current .= sprintf(
            "q %.2F G %.2F w %.2F %.2F m %.2F %.2F l S Q\n",
            $grey,
            $thickness,
            $x1,
            $this->height - $y1,
            $x2,
            $this->height - $y2,
        );

        return $this;
    }

    /**
     * A filled band, for a table header.
     */
    public function fill(float $x, float $y, float $width, float $height, float $grey = 0.94): self
    {
        $this->current .= sprintf(
            "q %.2F g %.2F %.2F %.2F %.2F re f Q\n",
            $grey,
            $x,
            $this->height - $y - $height,
            $width,
            $height,
        );

        return $this;
    }

    /**
     * Break a string into lines that fit a given width, so a long delivery
     * instruction wraps instead of running off the page.
     *
     * @return array<int, string>
     */
    public function wrap(string $text, float $maxWidth, float $size = 10.0, bool $bold = false): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;

            if ($this->widthOf($candidate, $size, $bold) > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line = $word;

                continue;
            }

            $line = $candidate;
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * How wide a string will be, near enough to wrap on.
     *
     * The real Helvetica metrics are a 256-entry table per face; this is the
     * average advance width for the two faces, which is accurate to a few
     * percent on ordinary sentences. Good enough to wrap a paragraph, and
     * nothing on these documents is set to touch a margin exactly.
     */
    public function widthOf(string $text, float $size = 10.0, bool $bold = false): float
    {
        return strlen($this->escape($text)) * $size * ($bold ? 0.55 : 0.5);
    }

    public function width(): float
    {
        return $this->width;
    }

    public function height(): float
    {
        return $this->height;
    }

    /**
     * Assemble the file.
     *
     * Objects are numbered as they are written and their byte offsets are
     * collected for the cross-reference table, which is what a reader uses to
     * find anything at all — a PDF with a wrong xref opens as a blank page.
     */
    public function render(): string
    {
        if ($this->started) {
            $this->pages[] = $this->current;
            $this->current = '';
            $this->started = false;
        }

        if ($this->pages === []) {
            $this->pages[] = '';
        }

        $pageCount = count($this->pages);

        /*
         * Fixed layout: 1 catalog, 2 pages tree, 3 and 4 the two fonts, then
         * two objects per page (the page itself and its content stream).
         */
        $firstPageObject = 5;
        $objects = [];

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];

        for ($index = 0; $index < $pageCount; $index++) {
            $kids[] = ($firstPageObject + $index * 2).' 0 R';
        }

        $objects[2] = sprintf(
            '<< /Type /Pages /Count %d /Kids [%s] >>',
            $pageCount,
            implode(' ', $kids),
        );

        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $index => $content) {
            $pageObject = $firstPageObject + $index * 2;
            $contentObject = $pageObject + 1;

            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] '
                .'/Resources << /Font << /%s 3 0 R /%s 4 0 R >> >> /Contents %d 0 R >>',
                $this->width,
                $this->height,
                self::FONT_REGULAR,
                self::FONT_BOLD,
                $contentObject,
            );

            $objects[$contentObject] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content,
            );
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $body);
        }

        $xrefOffset = strlen($pdf);
        $total = count($objects) + 1;

        $pdf .= sprintf("xref\n0 %d\n", $total);
        $pdf .= "0000000000 65535 f \n";

        for ($number = 1; $number < $total; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $total,
            $xrefOffset,
        );

        return $pdf;
    }

    /**
     * Make a string safe to put inside a PDF literal.
     *
     * Backslashes and brackets are the format's own delimiters. Anything
     * outside WinAnsi is flattened to ASCII rather than dropped: a receipt
     * for a buyer whose name carries an accent should read approximately
     * right rather than lose the letter.
     */
    private function escape(string $text): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);

        if ($ascii === false) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $ascii);
    }
}
