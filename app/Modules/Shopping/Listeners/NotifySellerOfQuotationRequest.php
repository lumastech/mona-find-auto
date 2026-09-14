<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Listeners;

use App\Modules\Shopping\Events\QuotationRequested;
use App\Modules\Shopping\Notifications\QuotationRequestedNotification;

/**
 * Tell the shop somebody has asked them for a price.
 *
 * A listener rather than a call inside the service, so that a buyer pressing
 * "Request a quote" never waits on a mail server answering — their request
 * appears either way, which is the part they can see.
 */
class NotifySellerOfQuotationRequest
{
    public function handle(QuotationRequested $event): void
    {
        $event->quotation->loadMissing(['seller.user', 'product', 'variant']);

        $event->quotation->seller->user->notify(
            new QuotationRequestedNotification($event->quotation),
        );
    }
}
