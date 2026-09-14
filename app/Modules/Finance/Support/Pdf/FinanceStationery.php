<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support\Pdf;

use App\Support\Money\Money;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonInterface;

/**
 * MonaFind's finance stationery: the masthead, the tables and the small print
 * every statement and invoice shares.
 *
 * A thin arrangement layer over PdfDocument, which knows about points and
 * content streams and nothing about what a document says. Keeping the two
 * apart is what lets the writer stay a general-purpose thing while the
 * house style lives in one place — change the margin here and every finance
 * document moves together.
 *
 * Vertical positions are returned rather than tracked internally, so a caller
 * composes a page by feeding each block the y the last one finished at. That
 * is what makes a table of unknown length safe to put in the middle of a
 * document.
 */
final class FinanceStationery
{
    public const MARGIN = 48.0;

    public const LINE_HEIGHT = 14.0;

    public function __construct(private readonly PdfDocument $pdf) {}

    public static function page(): self
    {
        $pdf = new PdfDocument;
        $pdf->addPage();

        return new self($pdf);
    }

    public function document(): PdfDocument
    {
        return $this->pdf;
    }

    public function right(): float
    {
        return $this->pdf->width() - self::MARGIN;
    }

    /**
     * The masthead: who issued this, what it is, and which one it is.
     */
    public function masthead(string $title, string $subject, ?CarbonInterface $date = null): float
    {
        $right = $this->right();

        $this->pdf->text(self::MARGIN, 60, 'MonaFindAuto', 18, true);
        $this->pdf->text(self::MARGIN, 76, 'Vehicle parts marketplace', 9);

        $this->pdf->textRight($right, 60, $title, 16, true);
        $this->pdf->textRight($right, 78, $subject, 11, true);
        $this->pdf->textRight($right, 92, $this->formatDate($date), 9);

        $this->pdf->line(self::MARGIN, 104, $right, 104, 1.0, 0.6);

        return 122.0;
    }

    /**
     * A labelled stack of lines — an address, a set of dates.
     *
     * @param  array<int, string|null>  $lines
     */
    public function block(float $x, float $y, string $heading, array $lines): float
    {
        $this->pdf->text($x, $y, $heading, 8, true);

        foreach (array_filter($lines) as $line) {
            $y += self::LINE_HEIGHT;
            $this->pdf->text($x, $y, (string) $line, 10);
        }

        return $y;
    }

    /**
     * A two-column table of labelled money amounts, which is what nearly every
     * figure on a statement or an invoice is.
     *
     * @param  array<int, array{0: string, 1: Money, 2?: bool}>  $rows  label, amount, emphasised
     */
    public function figures(float $y, string $heading, array $rows): float
    {
        $right = $this->right();

        $this->pdf->fill(self::MARGIN, $y - 10, $right - self::MARGIN, 18);
        $this->pdf->text(self::MARGIN + 4, $y, $heading, 9, true);
        $y += 8;

        foreach ($rows as $row) {
            $bold = $row[2] ?? false;
            $y += self::LINE_HEIGHT + 2;

            if ($bold) {
                $this->pdf->line(self::MARGIN, $y - 12, $right, $y - 12, 0.5, 0.7);
            }

            $this->pdf->text(self::MARGIN + 4, $y, $row[0], 10, $bold);
            $this->pdf->textRight($right, $y, $row[1]->format(), 10, $bold);
        }

        return $y + 16;
    }

    /**
     * The small print, wrapped to the page.
     *
     * @param  array<int, string>  $lines
     */
    public function note(float $y, array $lines): float
    {
        $right = $this->right();

        foreach ($lines as $line) {
            foreach ($this->pdf->wrap($line, $right - self::MARGIN, 8) as $wrapped) {
                $this->pdf->text(self::MARGIN, $y, $wrapped, 8);
                $y += 11;
            }
        }

        return $y;
    }

    public function heading(float $y, string $text): float
    {
        $this->pdf->text(self::MARGIN, $y, $text, 11, true);

        return $y + self::LINE_HEIGHT;
    }

    public function footer(string $text): void
    {
        $this->pdf->text(
            self::MARGIN,
            $this->pdf->height() - 40,
            $text.' · Generated '.$this->formatDate(now()),
            8,
        );
    }

    public function render(): string
    {
        return $this->pdf->render();
    }

    /**
     * Dates are shown in Lusaka time, because that is where everybody reading
     * these documents is standing.
     */
    private function formatDate(?CarbonInterface $date): string
    {
        return ($date ?? now())
            ->timezone((string) config('monafind.display_timezone', 'Africa/Lusaka'))
            ->format('j M Y, H:i');
    }
}
