<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Listeners;

use App\Modules\Shopping\Events\QuotationAnswered;
use App\Modules\Shopping\Notifications\QuotationAnsweredNotification;

/**
 * Tell the buyer their shop has answered.
 *
 * The one message that actually has to arrive: a quote nobody reads before
 * its validity date is a quote the seller wasted their time writing.
 */
class NotifyBuyerOfQuotationAnswer
{
    public function handle(QuotationAnswered $event): void
    {
        $event->quotation->loadMissing(['buyer', 'seller', 'product', 'variant']);

        $event->quotation->buyer->notify(
            new QuotationAnsweredNotification($event->quotation),
        );
    }
}
