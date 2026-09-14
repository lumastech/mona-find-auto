<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BackInStockSubscription;
use App\Modules\Inventory\Notifications\BackInStockNotification;
use Illuminate\Support\Facades\DB;

/**
 * "Tell me when this is back."
 *
 * The one rule worth stating: a subscriber hears once. A shop that restocks,
 * sells out and restocks again in the same afternoon would otherwise send the
 * same buyer three identical messages, and the third one is what makes them
 * turn notifications off for good.
 *
 * That guarantee is enforced by the row rather than by the job: a
 * subscription is marked spent inside the same locked update that claims it,
 * so two restock jobs racing each other cannot both claim the same one.
 */
class BackInStockService
{
    /**
     * Start waiting on a shelf.
     *
     * Re-subscribing after having been notified revives the same row, which
     * is what keeps "one notification per subscriber per restock" true
     * without accumulating rows a future restock would all fire at once.
     */
    public function subscribe(ProductVariant $variant, User $user): BackInStockSubscription
    {
        return BackInStockSubscription::query()->updateOrCreate(
            [
                'product_variant_id' => $variant->getKey(),
                'user_id' => $user->getKey(),
            ],
            [
                'product_id' => $variant->product_id,
                'notified_at' => null,
            ],
        );
    }

    public function unsubscribe(ProductVariant $variant, User $user): void
    {
        BackInStockSubscription::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('user_id', $user->getKey())
            ->delete();
    }

    public function isSubscribed(ProductVariant $variant, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return BackInStockSubscription::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('user_id', $user->getKey())
            ->pending()
            ->exists();
    }

    /**
     * Tell everyone still waiting on this shelf, once each.
     *
     * Each subscription is claimed with a conditional update — "set notified
     * where it is still null" — and only a claim that actually changed a row
     * sends anything. Two jobs racing on the same restock therefore split the
     * subscribers between them rather than both notifying all of them.
     *
     * @return int How many people were told.
     */
    public function notifySubscribers(ProductVariant $variant): int
    {
        $notified = 0;

        BackInStockSubscription::query()
            ->where('product_variant_id', $variant->getKey())
            ->pending()
            ->with('user')
            ->cursor()
            ->each(function (BackInStockSubscription $subscription) use ($variant, &$notified): void {
                if (! $this->claim($subscription)) {
                    return;
                }

                $subscription->user->notify(new BackInStockNotification($variant));

                $notified++;
            });

        return $notified;
    }

    /**
     * Take ownership of a subscription, or find somebody else already has.
     */
    private function claim(BackInStockSubscription $subscription): bool
    {
        $claimed = DB::table('back_in_stock_subscriptions')
            ->where('id', $subscription->getKey())
            ->whereNull('notified_at')
            ->update(['notified_at' => now(), 'updated_at' => now()]);

        return $claimed === 1;
    }
}
