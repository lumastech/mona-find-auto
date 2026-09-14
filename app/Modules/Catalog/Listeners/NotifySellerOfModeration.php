<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Events\ListingStatusChanged;
use App\Modules\Catalog\Notifications\ListingModerated;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tell the shop what moderation decided about their listing.
 *
 * Only the moves a moderator makes, not every move in the lifecycle. A seller
 * saving a draft does not need telling they saved a draft, and a listing
 * pulled automatically because its shop was suspended is covered by the
 * suspension notice — sending both would be telling somebody twice that they
 * are in trouble.
 */
class NotifySellerOfModeration implements ShouldQueue
{
    public string $queue = 'notifications';

    /**
     * The outcomes a seller hears about.
     *
     * @return array<int, ListingStatus>
     */
    public static function notifiableStates(): array
    {
        return [
            ListingStatus::Published,
            ListingStatus::Rejected,
            ListingStatus::Unpublished,
        ];
    }

    public function handle(ListingStatusChanged $event): void
    {
        if (! in_array($event->to, self::notifiableStates(), true)) {
            return;
        }

        /*
         * A seller putting their own listing up or taking it down is not
         * news to them. Only a decision somebody else made is.
         */
        if ($event->actor !== null && $event->actor->is($event->product->seller->user)) {
            return;
        }

        $event->product->seller->user->notify(
            new ListingModerated($event->product, $event->to, $event->reason),
        );
    }
}
