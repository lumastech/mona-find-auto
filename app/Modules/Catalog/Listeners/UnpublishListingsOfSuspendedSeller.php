<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Services\ListingModerationService;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Events\SellerVerificationChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Takes a suspended seller's stock down with their shop.
 *
 * Suspending a shop has to remove what it was selling, and it has to do so by
 * actually changing each listing rather than by filtering on read — otherwise
 * an order can still be placed against a listing that merely looks gone.
 *
 * This is the whole of Catalog's dependency on Sellers' workflow: one event,
 * one reaction, no reaching into the other module.
 */
class UnpublishListingsOfSuspendedSeller implements ShouldQueue
{
    public function __construct(private readonly ListingModerationService $moderation) {}

    public function handle(SellerVerificationChanged $event): void
    {
        if ($event->to !== VerificationStatus::Suspended) {
            return;
        }

        $this->moderation->unpublishAllFor(
            $event->seller,
            $event->actor,
            $event->reason ?? 'The seller account was suspended.',
        );
    }
}
