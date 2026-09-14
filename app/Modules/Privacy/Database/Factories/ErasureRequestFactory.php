<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Database\Factories;

use App\Models\User;
use App\Modules\Privacy\Enums\ErasureStatus;
use App\Modules\Privacy\Models\ErasureRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErasureRequest>
 */
class ErasureRequestFactory extends Factory
{
    protected $model = ErasureRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => ErasureStatus::Pending,
            'reason' => null,
            'requested_at' => now(),
            'erase_after' => now()->addDays((int) settings('privacy.erasure_grace_days', 14)),
        ];
    }

    /**
     * Past its grace period, so the nightly sweep will pick it up.
     */
    public function due(): self
    {
        return $this->state(fn (): array => [
            'status' => ErasureStatus::Pending,
            'requested_at' => now()->subDays(20),
            'erase_after' => now()->subDay(),
        ]);
    }

    public function blocked(string $reason = 'An order is still in progress.'): self
    {
        return $this->state(fn (): array => [
            'status' => ErasureStatus::Blocked,
            'blocked_reason' => $reason,
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => ErasureStatus::Completed,
            'completed_at' => now(),
            'report' => ['account' => ['users' => 1]],
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'status' => ErasureStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
