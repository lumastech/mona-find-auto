<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Privacy;

use App\Models\User;
use App\Modules\Inventory\Models\BackInStockSubscription;
use App\Modules\Privacy\Contracts\PersonalDataSource;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataSection;

/**
 * The listings a buyer asked to be told about.
 *
 * A back-in-stock subscription is a standing request to contact somebody, so
 * an erased account must not keep one — the whole point of the row is that it
 * produces a notification later.
 *
 * Stock movements are not here. They record what a seller did to their own
 * shelves and reference an `actor_id` rather than a buyer; they are also
 * append-only. A seller's erasure is a different matter, handled in Sellers.
 */
class InventoryPersonalData implements PersonalDataSource
{
    public function key(): string
    {
        return 'stock_alerts';
    }

    /**
     * @return array<int, PersonalDataSection>
     */
    public function export(User $user): array
    {
        return [
            PersonalDataSection::make(
                'Back-in-stock alerts you asked for',
                BackInStockSubscription::query()
                    ->where('user_id', $user->getKey())
                    ->with('product:id,name')
                    ->get()
                    ->map(static fn (BackInStockSubscription $subscription): array => [
                        'Listing' => $subscription->product->name,
                        'Asked on' => $subscription->created_at?->toDateTimeString(),
                        'Told on' => $subscription->notified_at?->toDateTimeString(),
                    ])->all(),
                'Sold-out listings you asked us to tell you about.',
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function erase(User $user, Anonymiser $anonymiser): array
    {
        return [
            'back_in_stock_subscriptions' => BackInStockSubscription::query()
                ->where('user_id', $user->getKey())
                ->delete(),
        ];
    }
}
