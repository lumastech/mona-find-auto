<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Listeners;

use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Events\SellerVerificationChanged;
use App\Modules\Sellers\Notifications\SellerVerificationDecided;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tell the shop what verification decided.
 *
 * Only the outcomes, not every step. A seller does not need telling that
 * their application moved from Submitted to UnderReview — that is the
 * platform describing its own workflow to somebody who is waiting for an
 * answer, and a stream of updates that are not the answer teaches them to
 * ignore the one that is.
 *
 * The inspection appointment is the exception: it is not an outcome, but it
 * is something the seller has to be in the shop for.
 */
class NotifySellerOfVerification implements ShouldQueue
{
    public string $queue = 'notifications';

    /**
     * @return array<int, VerificationStatus>
     */
    public static function notifiableStates(): array
    {
        return [
            VerificationStatus::Verified,
            VerificationStatus::Rejected,
            VerificationStatus::Suspended,
            VerificationStatus::InspectionScheduled,
        ];
    }

    public function handle(SellerVerificationChanged $event): void
    {
        if (! in_array($event->to, self::notifiableStates(), true)) {
            return;
        }

        $event->seller->user->notify(
            new SellerVerificationDecided($event->seller, $event->to, $event->reason),
        );
    }
}
