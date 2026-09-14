<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Exceptions;

use App\Modules\Shopping\Models\Quotation;
use RuntimeException;

/**
 * The buyer tried to take a price that is not on offer.
 */
class QuotationNotAcceptable extends RuntimeException
{
    public static function notQuoted(Quotation $quotation): self
    {
        return new self(sprintf(
            'Quotation %d is %s, so there is no price to accept.',
            $quotation->getKey(),
            $quotation->status->label(),
        ));
    }

    public static function expired(Quotation $quotation): self
    {
        return new self(sprintf(
            'Quotation %d passed its validity date and can no longer be accepted.',
            $quotation->getKey(),
        ));
    }
}
