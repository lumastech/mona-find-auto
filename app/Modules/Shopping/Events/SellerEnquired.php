<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Events;

use App\Modules\Shopping\Support\EnquiryReference;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A buyer wrote to a seller from a listing.
 *
 * Carries the channel's reference rather than a model, because the channel
 * behind it is swappable — when Messaging takes the job over the thread will
 * not be a Shopping row, and anything listening here should not have to
 * change.
 */
class SellerEnquired
{
    use Dispatchable, SerializesModels;

    public function __construct(public EnquiryReference $reference) {}
}
