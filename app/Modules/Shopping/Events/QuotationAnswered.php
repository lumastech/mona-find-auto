<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Events;

use App\Modules\Shopping\Models\Quotation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The seller answered a request — with a price, or with a refusal.
 *
 * One event for both, because from the buyer's side they are the same
 * moment: the shop has come back to them. What it said is on the quotation.
 */
class QuotationAnswered
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quotation $quotation) {}
}
