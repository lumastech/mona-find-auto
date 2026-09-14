<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Notifications\StockConfirmationReminder;
use App\Modules\Inventory\Services\FreshnessService;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Nudges sellers whose stock has gone unconfirmed, at day three and day five.
 *
 * One message per shop, not one per listing. A breaker with six hundred
 * listings receiving six hundred emails is not being reminded, they are being
 * driven off the platform — so the reminder counts the listings and links to
 * the one screen that confirms all of them.
 *
 * `stock_reminder_stage` on each listing records which reminder it has
 * already contributed to, so a seller who ignores the day-three message is
 * not sent it again every morning until day five.
 *
 * The day-five message is a different message: by then buyers are seeing a
 * "Stock unconfirmed" label, and saying so plainly is the only reminder that
 * changes behaviour.
 */
class SendStockConfirmationReminders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function uniqueId(): string
    {
        return 'stock-reminders:'.now()->toDateString();
    }

    public function handle(FreshnessService $freshness): void
    {
        $days = $freshness->reminderDays();

        /*
         * Walk the stages from the latest backwards, so a listing that is
         * eight days overdue is counted towards the final warning rather than
         * towards the day-three nudge it long ago passed.
         */
        foreach (array_reverse($days, preserve_keys: true) as $index => $day) {
            $stage = $index + 1;
            $isFinal = $stage === count($days);

            $this->remindForStage($day, $stage, $isFinal);
        }
    }

    /**
     * @param  int  $day  Days since confirmation that this stage fires at.
     * @param  int  $stage  1 for the first reminder, 2 for the second, and so on.
     */
    private function remindForStage(int $day, int $stage, bool $isFinal): void
    {
        /*
         * "At least $day whole days ago" is "before the start of the day
         * $day-1 days back". Subtracting $day outright would push the day-3
         * reminder out to day four, because a listing confirmed at the start
         * of day T-3 is not strictly before the start of day T-3.
         */
        $cutoff = now()->startOfDay()->subDays(max(0, $day - 1));

        $listings = Product::query()
            ->whereIn('id', app(FreshnessService::class)->sweepable()->select('id'))
            ->stockConfirmedBefore($cutoff)
            ->where('stock_reminder_stage', '<', $stage)
            ->get(['id', 'seller_id', 'freshness_confirmed_at']);

        $listings->groupBy('seller_id')->each(function (Collection $sellerListings, int $sellerId) use ($day, $stage, $isFinal): void {
            $seller = Seller::query()->with('user')->find($sellerId);

            if ($seller === null) {
                return;
            }

            $this->notify($seller, $sellerListings->count(), $day, $isFinal);

            Product::query()
                ->whereIn('id', $sellerListings->pluck('id'))
                ->update(['stock_reminder_stage' => $stage, 'stock_reminder_sent_at' => now()]);
        });
    }

    /**
     * One notification, three channels.
     *
     * The SMS half used to be a second dispatch to a purpose-built job here.
     * It is a channel on the notification now, which is what lets a seller
     * who wants fewer texts keep the email — the job had no way to ask.
     */
    private function notify(Seller $seller, int $listingCount, int $day, bool $isFinal): void
    {
        $seller->user->notify(new StockConfirmationReminder($seller, $listingCount, $day, $isFinal));
    }
}
