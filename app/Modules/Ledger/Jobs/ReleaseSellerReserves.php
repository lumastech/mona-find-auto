<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Jobs;

use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Money\RoundingMode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Trims each direct-settlement seller's reserve back to what it should be.
 *
 * The reserve is a ROLLING one: a percentage of trailing sales, not a pot
 * that fills up. So the job does not release "reserves older than N days" —
 * it works out what the seller should be holding against the business they
 * have done lately and gives back the difference. A seller whose volume has
 * fallen gets money back automatically; a seller whose volume is steady sees
 * nothing move, which is correct and is why most runs post nothing at all.
 *
 * Once a day is deliberate. The reserve exists to cover disputes that have
 * not been raised yet, and the sweeps that decide them run hourly; releasing
 * on the same cadence would hand back money the platform is about to need.
 *
 * The idempotency key carries the date, so running the job twice in a day —
 * a retry, an operator running it by hand — releases once. Tomorrow's run
 * gets its own key and can release again.
 */
class ReleaseSellerReserves implements ShouldQueue
{
    use Queueable;

    public function handle(OrderPostingService $postings, LedgerBalances $balances): void
    {
        $trailingDays = (int) settings('risk.reserve_trailing_days', 30);
        $percent = (string) settings('risk.reserve_percent', '10.00');
        $today = now()->toDateString();
        $since = now()->subDays($trailingDays);

        Seller::query()
            ->where('payment_mode', PaymentMode::Direct)
            ->eachById(function (Seller $seller) use ($postings, $balances, $percent, $since, $today): void {
                $held = $balances->sellerReserve($seller);

                if (! $held->isPositive()) {
                    return;
                }

                $required = $this->trailingSales($seller, $since)->percentage($percent, RoundingMode::HalfUp);
                $excess = $held->minus($required);

                if (! $excess->isPositive()) {
                    return;
                }

                $postings->releaseReserve(
                    $seller,
                    $excess,
                    'reserve-release:seller:'.$seller->getKey().':'.$today,
                );
            });
    }

    /**
     * What this seller has sold inside the trailing window.
     *
     * Counted on `paid_at` rather than on the order's current status: an
     * order that has since been disputed is exactly the kind of business the
     * reserve is being held against, so excluding it would shrink the reserve
     * at the moment it is most needed.
     */
    private function trailingSales(Seller $seller, mixed $since): Money
    {
        return Money::ofNgwee((int) Order::query()
            ->where('seller_id', $seller->getKey())
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->sum('total_ngwee'));
    }
}
