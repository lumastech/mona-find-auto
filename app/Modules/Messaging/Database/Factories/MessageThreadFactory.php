<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Database\Factories;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Enums\ThreadRole;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessageThreadParticipant;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageThread>
 */
class MessageThreadFactory extends Factory
{
    protected $model = MessageThread::class;

    /**
     * A conversation about a listing, between its shop and some buyer.
     *
     * The dedupe key is computed the way the service computes it, not made
     * up. A factory that invented one would make "one thread per subject per
     * pair" untestable — every factory-made thread would be unique whatever
     * the service did.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $listing = Product::factory();

        return [
            'subject_type' => (new Product)->getMorphClass(),
            'subject_id' => $listing,
            'seller_id' => fn (array $attributes): int => Product::query()
                ->whereKey($attributes['subject_id'])
                ->value('seller_id'),
            'subject_label' => fake()->words(3, true),
            'dedupe_key' => fake()->unique()->sha256(),
            'messages_count' => 0,
        ];
    }

    /**
     * A conversation about an order — the only kind on which contact details
     * survive the screen, and the only kind staff can ever read.
     */
    public function aboutOrder(?Order $order = null): self
    {
        return $this->state(function (array $attributes) use ($order): array {
            $order ??= Order::factory()->create();

            return [
                'subject_type' => $order->getMorphClass(),
                'subject_id' => $order->getKey(),
                'seller_id' => $order->seller_id,
                'subject_label' => 'Order '.$order->number,
            ];
        });
    }

    /**
     * Put two people in it, with the right dedupe key for that pair.
     */
    public function between(User $buyer, Seller $seller): self
    {
        return $this
            ->state(fn (array $attributes): array => ['seller_id' => $seller->getKey()])
            ->afterCreating(function (MessageThread $thread) use ($buyer, $seller): void {
                $subject = $thread->subject;

                MessageThreadParticipant::factory()->for($thread, 'thread')->create([
                    'user_id' => $buyer->getKey(),
                    'role' => ThreadRole::Buyer,
                ]);

                MessageThreadParticipant::factory()->for($thread, 'thread')->create([
                    'user_id' => $seller->user_id,
                    'role' => ThreadRole::Seller,
                ]);

                if ($subject !== null) {
                    $thread->forceFill([
                        'dedupe_key' => MessageThread::dedupeKeyFor($subject, [
                            (int) $buyer->getKey(),
                            (int) $seller->user_id,
                        ]),
                    ])->save();
                }
            });
    }
}
