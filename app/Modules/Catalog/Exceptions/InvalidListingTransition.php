<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Catalog\Enums\ListingStatus;
use RuntimeException;

/**
 * A move the listing lifecycle does not allow.
 */
class InvalidListingTransition extends RuntimeException
{
    public static function between(ListingStatus $from, ListingStatus $to): self
    {
        return new self(sprintf(
            'A listing cannot go from %s to %s.',
            $from->label(),
            $to->label(),
        ));
    }
}
