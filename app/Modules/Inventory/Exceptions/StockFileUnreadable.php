<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

/**
 * A bulk stock upload could not be parsed at all.
 *
 * Distinct from a file full of bad rows, which is a report rather than a
 * failure: this is a corrupt spreadsheet, a missing header, or a file so
 * large that reading it would be its own kind of outage. Nothing is applied
 * and the seller is told which of those it was.
 */
class StockFileUnreadable extends RuntimeException
{
    /**
     * @param  array<int, string>  $missing
     */
    public static function missingHeaders(array $missing): self
    {
        return new self(sprintf(
            'The file is missing the %s column%s. Download the template and use its headings.',
            implode(' and ', $missing),
            count($missing) === 1 ? '' : 's',
        ));
    }

    public static function empty(): self
    {
        return new self('The file has a heading row but no stock rows under it.');
    }

    public static function tooManyRows(int $rows, int $limit): self
    {
        return new self(sprintf(
            'The file has %d rows and the limit is %d. Split it and upload the parts separately.',
            $rows,
            $limit,
        ));
    }

    public static function unsupportedFormat(string $extension): self
    {
        return new self(sprintf(
            'MonaFind cannot read a .%s file. Upload the CSV or XLSX template.',
            $extension,
        ));
    }

    public static function corrupt(string $detail): self
    {
        return new self('The file could not be opened: '.$detail);
    }
}
