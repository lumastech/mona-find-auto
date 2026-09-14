<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Models\User;
use App\Modules\Payments\Notifications\SellerRevertedToEscrow;
use App\Modules\Payments\Services\PaymentModeService;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Take direct settlement away from sellers whose disputes have got out of hand.
 *
 * Runs daily, not hourly. The dispute rate is a trailing measure and moves
 * slowly; checking it every hour would only add twenty-three chances a day for
 * a seller to be reverted by a rounding wobble.
 *
 * One-way on purpose — see PaymentModeService. Nothing here ever grants direct
 * settlement; only an administrator does that.
 */
class RevertRiskySellersToEscrow implements ShouldQueue
{
    use Queueable;

    public function handle(PaymentModeService $modes): void
    {
        $threshold = $modes->threshold();

        Seller::query()
            ->where('payment_mode', PaymentMode::Direct)
            ->chunkById(100, function ($sellers) use ($modes, $threshold): void {
                foreach ($sellers as $seller) {
                    if (! $modes->shouldRevert($seller)) {
                        continue;
                    }

                    $rate = $modes->disputeRatePercent($seller);

                    $modes->revertToEscrow($seller, $rate, $threshold);

                    $this->notifyAdministrators($seller, $rate, $threshold);
                }
            });
    }

    /**
     * Tell Finance and the platform admins.
     *
     * A seller silently moved back to escrow is a support ticket nobody can
     * explain — the seller notices their payouts stopped and asks why.
     */
    private function notifyAdministrators(Seller $seller, float $rate, float $threshold): void
    {
        $staff = User::query()
            ->role([Role::Finance->value, Role::PlatformAdmin->value])
            ->get();

        if ($staff->isEmpty()) {
            return;
        }

        Notification::send($staff, new SellerRevertedToEscrow($seller, $rate, $threshold));
    }
}
