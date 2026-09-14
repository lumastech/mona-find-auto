<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Database\Factories;

use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Mechanics\Enums\MechanicStatus;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicSpeciality;
use App\Support\Roles\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @extends Factory<MechanicProfile>
 */
class MechanicProfileFactory extends Factory
{
    protected $model = MechanicProfile::class;

    /**
     * Profiles come out approved, because that is what nearly every test of
     * the directory, the profile page and endorsements needs. The draft(),
     * submitted() and rejected() states cover the workflow itself.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /* The province comes from the city, so the two can never disagree. */
        $city = City::factory()->create();
        $name = fake()->name();

        return [
            'user_id' => User::factory(),
            'display_name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'headline' => fake()->sentence(5),
            'bio' => fake()->paragraph(),
            'qualification' => fake()->randomElement([
                'City & Guilds Motor Vehicle Craft Studies',
                'TEVETA Craft Certificate — Automotive Mechanics',
                'Diploma in Automotive Engineering',
            ]),
            'qualification_institution' => fake()->randomElement([
                'Lusaka Trades Training Institute',
                'Northern Technical College',
                'Evelyn Hone College',
            ]),
            'qualification_year' => fake()->numberBetween(2000, 2024),
            'years_experience' => fake()->numberBetween(1, 30),
            'province_id' => $city->province_id,
            'city_id' => $city->id,
            'street' => fake()->streetName(),
            'phone' => UserFactory::zambianMobile(),
            'email' => fake()->unique()->safeEmail(),
            'is_mobile' => fake()->boolean(40),
            'accepting_work' => true,
            'status' => MechanicStatus::Approved,
            'submitted_at' => now()->subDays(10),
            'approved_at' => now()->subDays(3),
        ];
    }

    /**
     * Give an approved mechanic's account the mechanic role, as approval
     * does. Without it they cannot ask a shop for an endorsement.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (MechanicProfile $profile): void {
            if ($profile->status->isApproved()) {
                SpatieRole::findOrCreate(Role::Mechanic->value, 'web');
                $profile->user->assignRole(Role::Mechanic->value);
            }
        });
    }

    public function draft(): static
    {
        return $this->state([
            'status' => MechanicStatus::Draft,
            'submitted_at' => null,
            'approved_at' => null,
        ]);
    }

    public function submitted(): static
    {
        return $this->state([
            'status' => MechanicStatus::Submitted,
            'submitted_at' => now()->subDay(),
            'approved_at' => null,
        ]);
    }

    public function underReview(): static
    {
        return $this->state([
            'status' => MechanicStatus::UnderReview,
            'submitted_at' => now()->subDays(2),
            'approved_at' => null,
        ]);
    }

    public function rejected(string $reason = 'We could not confirm the qualification you listed.'): static
    {
        return $this->state([
            'status' => MechanicStatus::Rejected,
            'rejection_reason' => $reason,
            'approved_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state([
            'status' => MechanicStatus::Suspended,
        ]);
    }

    /**
     * Attach specialities, creating them when the caller did not.
     *
     * @param  array<int, MechanicSpeciality>  $specialities
     */
    public function withSpecialities(array $specialities = [], int $count = 2): static
    {
        return $this->afterCreating(function (MechanicProfile $profile) use ($specialities, $count): void {
            $specialities = $specialities === []
                ? MechanicSpeciality::factory()->count($count)->create()->all()
                : $specialities;

            $profile->specialities()->sync(array_map(
                static fn (MechanicSpeciality $speciality): int => $speciality->getKey(),
                $specialities,
            ));
        });
    }
}
