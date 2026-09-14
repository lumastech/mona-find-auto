<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Sellers\Models\Seller;
use RuntimeException;

/**
 * A shop that may not put stock in front of buyers tried to.
 *
 * Sellers may write drafts from the moment they apply, but only a verified
 * seller — or one still waiting on a decision — may send a listing for
 * review. A rejected or suspended shop may not.
 */
class SellerMayNotList extends RuntimeException
{
    public static function inStatus(Seller $seller): self
    {
        return new self(sprintf(
            'A %s seller cannot send listings for review.',
            strtolower($seller->verification_status->label()),
        ));
    }
}
