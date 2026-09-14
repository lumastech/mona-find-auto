<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\ListingReviewEvent;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingReviewEvent>
 */
class ListingReviewEventFactory extends Factory
{
    protected $model = ListingReviewEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'from_status' => ListingStatus::PendingReview,
            'to_status' => ListingStatus::Published,
            'reason' => null,
            'field_reasons' => null,
            'note' => null,
            'actor_id' => null,
            'created_at' => now(),
        ];
    }

    /**
     * @param  array<string, string>  $fieldReasons
     */
    public function rejection(string $reason, array $fieldReasons = []): static
    {
        return $this->state([
            'to_status' => ListingStatus::Rejected,
            'reason' => $reason,
            'field_reasons' => $fieldReasons === [] ? null : $fieldReasons,
        ]);
    }
}
