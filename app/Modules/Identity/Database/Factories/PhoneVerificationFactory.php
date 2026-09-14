<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Models\User;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Models\PhoneVerification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PhoneVerification>
 */
class PhoneVerificationFactory extends Factory
{
    protected $model = PhoneVerification::class;

    /**
     * The plaintext code the default state hashes, so a test can type it in.
     */
    public const CODE = '123456';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phone' => UserFactory::zambianMobile(),
            'purpose' => OtpPurpose::PhoneVerification,
            'code_hash' => Hash::make(self::CODE),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'last_sent_at' => now(),
        ];
    }

    public function forCode(string $code): static
    {
        return $this->state(['code_hash' => Hash::make($code)]);
    }

    public function expired(): static
    {
        return $this->state([
            'expires_at' => now()->subMinute(),
            'last_sent_at' => now()->subMinutes(11),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(['consumed_at' => now()]);
    }

    public function forPasswordReset(): static
    {
        return $this->state(['purpose' => OtpPurpose::PasswordReset]);
    }

    /**
     * Sent long enough ago that another code may be requested.
     */
    public function resendable(): static
    {
        return $this->state(['last_sent_at' => now()->subHour()]);
    }
}
