<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

/**
 * Spreadsheet column letters, both ways.
 *
 * A1 notation is base-26 with no zero — column 26 is "Z" and column 27 is
 * "AA", not "BA" — which is exactly the arithmetic people get wrong when they
 * write it inline. It is here once, with tests.
 */
final class CellReference
{
    /**
     * Zero-based column index to letters: 0 → "A", 26 → "AA".
     */
    public static function forColumn(int $index): string
    {
        $letters = '';

        for ($remaining = $index; $remaining >= 0; $remaining = intdiv($remaining, 26) - 1) {
            $letters = chr(65 + $remaining % 26).$letters;
        }

        return $letters;
    }

    /**
     * A cell reference back to a zero-based column index: "B7" → 1.
     */
    public static function toColumn(string $reference): int
    {
        preg_match('/^([A-Za-z]+)/', $reference, $matches);

        $letters = strtoupper($matches[1] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
