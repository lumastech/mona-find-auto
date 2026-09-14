<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Database\Factories;

use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @extends Factory<Seller>
 */
class SellerFactory extends Factory
{
    protected $model = Seller::class;

    /**
     * Sellers come out verified, because that is what nearly every test of
     * anything downstream needs. The draft(), submitted() and rejected()
     * states cover the workflow itself.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /* The province comes from the city, so the two can never disagree. */
        $city = City::factory()->create();
        $businessName = fake()->unique()->company().' Auto';

        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(SellerType::cases()),
            'business_name' => $businessName,
            'slug' => Str::slug($businessName).'-'.fake()->unique()->numberBetween(1, 999999),
            'registration_number' => strtoupper(fake()->bothify('##????####')),
            'description' => fake()->paragraph(),
            'province_id' => $city->province_id,
            'city_id' => $city->id,
            'street' => fake()->streetName(),
            'plot_number' => (string) fake()->numberBetween(1, 9999),
            'phone' => UserFactory::zambianMobile(),
            'email' => fake()->unique()->companyEmail(),
            'contact_person' => fake()->name(),
            'opening_hours' => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => ['open' => '08:00', 'close' => '13:00'],
            ],
            'verification_status' => VerificationStatus::Verified,
            'submitted_at' => now()->subDays(7),
            'verified_at' => now()->subDays(2),
            'payment_mode' => PaymentMode::Escrow,
        ];
    }

    /**
     * Give the seller's owner the seller role, as submitting an application
     * does. Without this the owner cannot open the portal.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Seller $seller): void {
            if ($seller->verification_status === VerificationStatus::Draft) {
                return;
            }

            SpatieRole::findOrCreate(Role::Seller->value, 'web');
            $seller->user->assignRole(Role::Seller->value);
        });
    }

    public function ofType(SellerType $type): static
    {
        return $this->state([
            'type' => $type,
            'bay_count' => $type->hasWorkshopCapacity() ? fake()->numberBetween(1, 12) : null,
        ]);
    }

    /**
     * A sign-up still in progress: no badge, invisible to buyers.
     */
    public function draft(): static
    {
        return $this->state([
            'verification_status' => VerificationStatus::Draft,
            'submitted_at' => null,
            'verified_at' => null,
        ]);
    }

    public function submitted(): static
    {
        return $this->state([
            'verification_status' => VerificationStatus::Submitted,
            'submitted_at' => now(),
            'verified_at' => null,
        ]);
    }

    public function underReview(): static
    {
        return $this->state([
            'verification_status' => VerificationStatus::UnderReview,
            'submitted_at' => now()->subDay(),
            'verified_at' => null,
        ]);
    }

    public function rejected(string $reason = 'The registration number did not match PACRA records.'): static
    {
        return $this->state([
            'verification_status' => VerificationStatus::Rejected,
            'rejection_reason' => $reason,
            'verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(['verification_status' => VerificationStatus::Suspended]);
    }

    /**
     * A business that never got round to registering with PACRA. Cannot be
     * verified until it does.
     */
    public function withoutRegistrationNumber(): static
    {
        return $this->state(['registration_number' => null]);
    }

    public function direct(): static
    {
        return $this->state(['payment_mode' => PaymentMode::Direct]);
    }

    /**
     * Put the business in an existing city rather than inventing a new one.
     */
    public function in(City $city): static
    {
        return $this->state(['province_id' => $city->province_id, 'city_id' => $city->id]);
    }

    /**
     * A business with a map pin dropped on it.
     */
    public function pinned(): static
    {
        return $this->state([
            'latitude' => fake()->randomFloat(6, -18.0, -8.2),
            'longitude' => fake()->randomFloat(6, 22.0, 33.7),
            'formatted_address' => fake()->address(),
        ]);
    }
}
