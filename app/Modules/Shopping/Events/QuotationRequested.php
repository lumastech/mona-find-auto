<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Events;

use App\Modules\Shopping\Models\Quotation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A buyer asked a shop for a price.
 *
 * The seller learns of it from a listener rather than from the request that
 * created it: an SMS to a Zamtel number is not something a buyer should wait
 * on to see their own request appear.
 */
class QuotationRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quotation $quotation) {}
}
