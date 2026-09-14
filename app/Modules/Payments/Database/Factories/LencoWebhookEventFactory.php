<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Factories;

use App\Modules\Payments\Models\LencoWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LencoWebhookEvent>
 */
class LencoWebhookEventFactory extends Factory
{
    protected $model = LencoWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reference = 'MFA-'.strtoupper($this->faker->bothify('??######')).'-1';

        return [
            'lenco_event_id' => 'collection.successful:'.$this->faker->uuid(),
            'event' => 'collection.successful',
            'reference' => $reference,
            'payload' => [
                'event' => 'collection.successful',
                'data' => ['reference' => $reference, 'status' => 'successful'],
            ],
            'received_at' => now(),
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (): array => ['processed_at' => now(), 'attempts' => 1]);
    }
}
