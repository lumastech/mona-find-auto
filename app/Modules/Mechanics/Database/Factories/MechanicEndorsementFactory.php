<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Database\Factories;

use App\Modules\Mechanics\Enums\EndorsementStatus;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An endorsement that a shop has granted.
 *
 * `requested_by` is derived from whichever profile the endorsement ends up
 * on, rather than being a user of its own: a fixture whose request came from
 * an unrelated account would pass its own assertions and prove nothing about
 * the authorisation rules.
 *
 * It is a closure rather than an eagerly built profile so that between()
 * does not leave an orphan approved mechanic behind — one extra row in the
 * directory is enough to make a "this profile is hidden" assertion pass or
 * fail for the wrong reason.
 *
 * @extends Factory<MechanicEndorsement>
 */
class MechanicEndorsementFactory extends Factory
{
    protected $model = MechanicEndorsement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mechanic_profile_id' => MechanicProfile::factory(),
            'seller_id' => Seller::factory(),
            'status' => EndorsementStatus::Endorsed,
            'message' => fake()->sentence(10),
            /* Resolved after mechanic_profile_id above, so it is that profile's owner. */
            'requested_by' => static fn (array $attributes): int => MechanicProfile::query()
                ->whereKey($attributes['mechanic_profile_id'])
                ->sole()
                ->user_id,
            'requested_at' => now()->subDays(5),
            'decided_at' => now()->subDays(4),
            'endorsed_at' => now()->subDays(4),
        ];
    }

    /**
     * The relationship between one mechanic and one shop.
     *
     * Named `between` rather than `for` because Factory::for() already means
     * something else entirely.
     */
    public function between(MechanicProfile $profile, Seller $seller): static
    {
        return $this->state([
            'mechanic_profile_id' => $profile->getKey(),
            'seller_id' => $seller->getKey(),
            'requested_by' => $profile->user_id,
        ]);
    }

    public function requested(): static
    {
        return $this->state([
            'status' => EndorsementStatus::Requested,
            'requested_at' => now()->subDay(),
            'decided_by' => null,
            'decided_at' => null,
            'endorsed_at' => null,
        ]);
    }

    public function declined(): static
    {
        return $this->state([
            'status' => EndorsementStatus::Declined,
            'decided_at' => now()->subDay(),
            'endorsed_at' => null,
        ]);
    }

    public function revoked(string $reason = 'They no longer work out of our yard.'): static
    {
        return $this->state([
            'status' => EndorsementStatus::Revoked,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);
    }
}
