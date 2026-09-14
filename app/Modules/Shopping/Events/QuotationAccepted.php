<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Events;

use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Models\Quotation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The buyer took the quoted price, and it is now a cart line.
 *
 * Carries the line as well as the quotation because the two are only
 * meaningful together — the offer, and the thing it became.
 */
class QuotationAccepted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Quotation $quotation,
        public CartItem $line,
    ) {}
}
