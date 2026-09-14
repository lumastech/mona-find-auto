<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Exceptions;

use App\Modules\Shopping\Enums\QuotationStatus;
use App\Modules\Shopping\Models\Quotation;
use RuntimeException;

/**
 * A move the quotation lifecycle does not allow.
 */
class InvalidQuotationTransition extends RuntimeException
{
    public static function between(QuotationStatus $from, QuotationStatus $to): self
    {
        return new self(sprintf(
            'A quotation cannot go from %s to %s.',
            $from->label(),
            $to->label(),
        ));
    }

    /**
     * A price that was never good for a single day is a typo, not an offer.
     */
    public static function validityInThePast(Quotation $quotation): self
    {
        return new self(sprintf(
            'Quotation %d cannot be quoted with a validity date that has already passed.',
            $quotation->getKey(),
        ));
    }
}
