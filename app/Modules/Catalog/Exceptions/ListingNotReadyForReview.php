<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

/**
 * A listing was sent for review before it was fit to be reviewed.
 *
 * The reasons are field-keyed so a controller can turn them straight into a
 * ValidationException and the seller sees them beside the fields at fault
 * rather than as one sentence at the top of the form.
 */
class ListingNotReadyForReview extends RuntimeException
{
    /**
     * @param  array<string, string>  $reasons  Keyed by form field.
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons) ?: 'This listing is not ready to be reviewed.');
    }

    /**
     * @param  array<string, string>  $reasons
     */
    public static function because(array $reasons): self
    {
        return new self($reasons);
    }
}
