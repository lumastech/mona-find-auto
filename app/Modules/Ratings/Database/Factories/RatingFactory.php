<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Database\Factories;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Models\Rating;
use App\Support\Content\ScreenFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A buyer's public review of a shop, on a completed order.
 *
 * The order is built eagerly rather than lazily because the rater, the ratee
 * and the source all have to agree — a fixture whose review is about one shop
 * and whose order was placed with another would pass its own assertions and
 * prove nothing.
 *
 * @extends Factory<Rating>
 */
class RatingFactory extends Factory
{
    protected $model = Rating::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $order = Order::factory()->completed()->createOne();

        return [
            ...$this->onOrder($order, RatingDirection::BuyerToSeller),
            'stars' => $this->faker->numberBetween(3, 5),
            'body' => $this->faker->sentence(12),
            'status' => RatingStatus::Published,
            'screen_flags' => [],
            'published_at' => now(),
        ];
    }

    /**
     * The buyer's public review of the shop on a given order.
     */
    public function forOrder(Order $order, RatingDirection $direction = RatingDirection::BuyerToSeller): static
    {
        return $this->state(fn (): array => $this->onOrder($order, $direction));
    }

    /**
     * The shop's private rating of the buyer.
     */
    public function sellerRatingBuyer(): static
    {
        return $this->state(function (array $attributes): array {
            $order = Order::query()->find($attributes['source_id'] ?? null);

            if (! $order instanceof Order) {
                $order = Order::factory()->completed()->createOne();
            }

            return $this->onOrder($order, RatingDirection::SellerToBuyer);
        });
    }

    public function stars(int $stars): static
    {
        return $this->state(['stars' => $stars]);
    }

    public function pendingReview(): static
    {
        return $this->state([
            'status' => RatingStatus::PendingReview,
            'screen_flags' => [ScreenFlag::Profanity->value],
            'published_at' => null,
        ]);
    }

    public function hidden(string $reason = 'Abusive language.'): static
    {
        return $this->state([
            'status' => RatingStatus::Hidden,
            'moderation_reason' => $reason,
            'moderated_at' => now(),
        ]);
    }

    public function withReply(string $body = 'Thanks for the feedback — the part has been replaced.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'reply_body' => $body,
            'replied_by' => $attributes['replied_by'] ?? User::factory(),
            'replied_at' => now(),
        ]);
    }

    /**
     * The five columns that have to agree with each other.
     *
     * @return array<string, mixed>
     */
    private function onOrder(Order $order, RatingDirection $direction): array
    {
        $buyerRates = $direction === RatingDirection::BuyerToSeller;

        return [
            'direction' => $direction,
            'source' => $direction->source(),
            'source_type' => $order->getMorphClass(),
            'source_id' => $order->getKey(),
            'rater_type' => $buyerRates ? $order->buyer->getMorphClass() : $order->seller->getMorphClass(),
            'rater_id' => $buyerRates ? $order->user_id : $order->seller_id,
            'ratee_type' => $buyerRates ? $order->seller->getMorphClass() : $order->buyer->getMorphClass(),
            'ratee_id' => $buyerRates ? $order->seller_id : $order->user_id,
            'submitted_by' => $buyerRates ? $order->user_id : $order->seller->user_id,
            'verified_purchase' => true,
        ];
    }
}
