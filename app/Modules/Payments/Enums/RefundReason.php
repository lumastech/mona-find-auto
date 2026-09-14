<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Why the money went back.
 *
 * Kept separate from the dispute or order it came from because the reason
 * outlives both, and because "how much did auto-cancellation cost us last
 * month" should be one query rather than an archaeology exercise.
 */
enum RefundReason: string
{
    case Dispute = 'dispute';
    case AutoCancel = 'auto_cancel';
    case SellerCancelled = 'seller_cancelled';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Dispute => 'Dispute resolution',
            self::AutoCancel => 'Order auto-cancelled',
            self::SellerCancelled => 'Seller cancelled',
            self::Admin => 'Administrator decision',
        };
    }
}
