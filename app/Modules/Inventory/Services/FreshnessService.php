<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Inventory\Events\ProductFreshnessChanged;
use App\Modules\Inventory\Events\StockConfirmed;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock freshness: the platform's answer to a listing that quietly stops
 * being true.
 *
 * A Zambian parts shop sells the same alternator over the counter and on
 * MonaFind, and nobody updates a website mid-transaction. So the platform
 * asks: confirm every few days, or slide down the rankings, then carry a
 * warning, then come off the storefront. One tap confirms a whole shop.
 *
 * The windows are settings rather than constants because the right number is
 * a market question — a breaker's yard turns over slower than a filter shop.
 * The ordering of the states is not configurable: each is strictly worse than
 * the last.
 *
 * This is the only thing that writes `freshness_state` and
 * `freshness_confirmed_at`; the daily sweep and a seller's confirmation are
 * the same code path in opposite directions.
 */
class FreshnessService
{
    /**
     * The configured windows, in days.
     *
     * @return array{fresh: int, ageing: int, hidden: int}
     */
    public function thresholds(): array
    {
        return [
            'fresh' => settings()->integer('freshness.fresh_max_days', 3),
            'ageing' => settings()->integer('freshness.ageing_max_days', 5),
            'hidden' => settings()->integer('freshness.hidden_after_days', 14),
        ];
    }

    /**
     * The days at which a seller is reminded, ascending.
     *
     * @return array<int, int>
     */
    public function reminderDays(): array
    {
        /** @var array<array-key, mixed> $days */
        $days = settings()->array('freshness.reminder_days', [3, 5]);

        $days = array_values(array_filter(array_map('intval', $days), static fn (int $day): bool => $day > 0));
        sort($days);

        return $days === [] ? [3, 5] : $days;
    }

    /**
     * The state a listing should be in right now.
     */
    public function stateFor(Product $product): FreshnessState
    {
        return FreshnessState::forAge($product->daysSinceStockConfirmed(), $this->thresholds());
    }

    /**
     * Recompute every published listing's state and announce what moved.
     *
     * Runs daily rather than on read. Deriving the state in the query would
     * mean the storefront, search and the seller portal each recomputing it
     * with their own idea of "now", and nothing to fire an event off when a
     * listing crosses a boundary at three in the morning.
     *
     * Only listings a buyer could otherwise see are swept. A draft has no
     * freshness worth arguing about, and archived stock is gone.
     *
     * @return array<string, int> How many listings landed in each state.
     */
    public function refreshStates(): array
    {
        $thresholds = $this->thresholds();
        $counts = array_fill_keys(FreshnessState::values(), 0);

        $this->sweepable()->each(function (Product $product) use ($thresholds, &$counts): void {
            $state = FreshnessState::forAge($product->daysSinceStockConfirmed(), $thresholds);

            $counts[$state->value]++;

            $this->transitionTo($product, $state);
        });

        return $counts;
    }

    /**
     * Record that a seller has vouched for one listing's stock.
     */
    public function confirm(Product $product, ?User $actor = null): Product
    {
        $product->forceFill([
            'freshness_confirmed_at' => now(),
            /* A fresh promise wipes the reminder history: the next lapse starts over. */
            'stock_reminder_stage' => 0,
            'stock_reminder_sent_at' => null,
        ])->save();

        $this->transitionTo($product, FreshnessState::Fresh);

        StockConfirmed::dispatch($product->seller, 1, false, $actor);

        return $product;
    }

    /**
     * The one-tap "all my stock is accurate".
     *
     * Every listing the shop has that a buyer could see, in one go. It is the
     * single most important interaction in this module: a confirmation flow
     * that takes a seller more than one tap is a confirmation flow that stops
     * happening by the second week.
     *
     * @return int How many listings were confirmed.
     */
    public function confirmAllFor(Seller $seller, ?User $actor = null): int
    {
        $confirmed = 0;

        $this->sweepable()
            ->where('seller_id', $seller->getKey())
            ->each(function (Product $product) use (&$confirmed): void {
                $product->forceFill([
                    'freshness_confirmed_at' => now(),
                    'stock_reminder_stage' => 0,
                    'stock_reminder_sent_at' => null,
                ])->save();

                $this->transitionTo($product, FreshnessState::Fresh);

                $confirmed++;
            });

        audit(
            $actor,
            'stock.confirmed_all',
            $seller,
            null,
            ['listings' => $confirmed],
            'Seller confirmed all stock is accurate.',
        );

        StockConfirmed::dispatch($seller, $confirmed, true, $actor);

        return $confirmed;
    }

    /**
     * Which of this shop's listings are waiting on a confirmation, by state.
     *
     * @return array<string, int>
     */
    public function outstandingFor(Seller $seller): array
    {
        $counts = $this->sweepable()
            ->where('seller_id', $seller->getKey())
            ->toBase()
            ->selectRaw('freshness_state, count(*) as total')
            ->groupBy('freshness_state')
            ->pluck('total', 'freshness_state')
            ->all();

        return array_merge(
            array_fill_keys(FreshnessState::values(), 0),
            array_map('intval', $counts),
        );
    }

    /**
     * Listings that carry a freshness state at all.
     *
     * Published and unpublished both count: an unpublished listing is coming
     * back one day, and its clock should not have been paused while it was
     * down.
     *
     * @return Builder<Product>
     */
    public function sweepable(): Builder
    {
        return Product::query()
            ->whereIn('status', self::sweepableStatuses())
            /*
             * Every caller of this query drives a freshness transition, and
             * every transition is observed by Search, which rebuilds the
             * listing's index document and reads its seller. Eager-loading
             * here rather than at each call site is what stops the nightly
             * sweep — and the one-tap "all my stock is accurate" — issuing a
             * seller query per listing.
             */
            ->with('seller');
    }

    /**
     * The listing statuses that carry a freshness clock.
     *
     * Exposed so a caller filtering listings inside a relation can apply the
     * same rule without a subquery back onto the products table.
     *
     * @return array<int, ListingStatus>
     */
    public static function sweepableStatuses(): array
    {
        return [ListingStatus::Published, ListingStatus::Unpublished];
    }

    /**
     * Move a listing to a state, and say so if it actually moved.
     *
     * `freshness_hidden_at` is stamped on the way down and cleared on the way
     * back up, so "how long was this listing dark" is answerable without
     * reading the event log.
     */
    private function transitionTo(Product $product, FreshnessState $state): void
    {
        $from = $product->freshness_state;

        if ($from === $state) {
            return;
        }

        $product->forceFill([
            'freshness_state' => $state,
            'freshness_hidden_at' => $state === FreshnessState::Hidden
                ? ($product->freshness_hidden_at ?? now())
                : null,
        ])->save();

        ProductFreshnessChanged::dispatch($product, $from, $state);
    }
}
