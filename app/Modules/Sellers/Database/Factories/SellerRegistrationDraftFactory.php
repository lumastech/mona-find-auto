<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Database\Factories;

use App\Models\User;
use App\Modules\Sellers\Enums\RegistrationStep;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Models\SellerRegistrationDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerRegistrationDraft>
 */
class SellerRegistrationDraftFactory extends Factory
{
    protected $model = SellerRegistrationDraft::class;

    /**
     * A sign-up that has got no further than choosing a business type.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'current_step' => RegistrationStep::Business,
            'furthest_step' => RegistrationStep::Type,
            'data' => ['type' => ['type' => SellerType::SparePartsShop->value]],
        ];
    }

    public function ofType(SellerType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'data' => [...$attributes['data'], 'type' => ['type' => $type->value]],
        ]);
    }

    /**
     * A brand-new draft, before even the business type has been chosen.
     */
    public function untouched(): static
    {
        return $this->state([
            'current_step' => RegistrationStep::Type,
            'furthest_step' => RegistrationStep::Type,
            'data' => [],
        ]);
    }
}
