<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\MobileNetwork;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Support\ZambianPhone;
use App\Support\Roles\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Accounts come out Active and verified, because that is what almost
     * every test needs; the pending(), suspended() and closed() states cover
     * the rest.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();
        $phone = self::zambianMobile();

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone' => $phone,
            'phone_network' => ZambianPhone::tryParse($phone)?->network->value,
            'phone_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => AccountStatus::Active,
            'status_changed_at' => now(),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * A unique, valid Zambian mobile number in E.164.
     *
     * Shared with the address and OTP factories so every generated number in
     * the test suite is one the validator would actually accept.
     */
    public static function zambianMobile(): string
    {
        $prefix = fake()->randomElement(MobileNetwork::allPrefixes());

        return '+260'.$prefix.fake()->unique()->numerify('#######');
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Registered, but the phone number has not been proven yet.
     */
    public function pending(): static
    {
        return $this->state([
            'status' => AccountStatus::Pending,
            'phone_verified_at' => null,
        ]);
    }

    public function suspended(string $reason = 'Selling counterfeit parts.'): static
    {
        return $this->state([
            'status' => AccountStatus::Suspended,
            'status_reason' => $reason,
            'status_changed_at' => now(),
        ]);
    }

    public function closed(string $reason = 'Closed at the account holder’s request.'): static
    {
        return $this->state([
            'status' => AccountStatus::Closed,
            'status_reason' => $reason,
            'status_changed_at' => now(),
        ]);
    }

    /**
     * An account with a postal address, for the screens that show one.
     */
    public function withAddress(): static
    {
        return $this->state(function (): array {
            $city = City::factory()->create();

            return [
                'province_id' => $city->province_id,
                'city_id' => $city->id,
                'street' => fake()->streetName(),
                'plot_number' => (string) fake()->numberBetween(1, 9999),
            ];
        });
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Create the account holding the given platform roles, creating the roles
     * themselves if the seeder has not run.
     */
    public function withRole(Role ...$roles): static
    {
        return $this->afterCreating(function (User $user) use ($roles): void {
            foreach ($roles as $role) {
                SpatieRole::findOrCreate($role->value, 'web');
            }

            $user->assignRole(array_map(static fn (Role $role): string => $role->value, $roles));
        });
    }
}
