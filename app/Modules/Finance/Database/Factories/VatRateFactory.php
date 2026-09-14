<?php

declare(strict_types=1);

namespace App\Modules\Finance\Database\Factories;

use App\Modules\Finance\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VatRate>
 */
class VatRateFactory extends Factory
{
    protected $model = VatRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate_percent' => '16.00',
            'effective_from' => now()->subYear()->startOfYear()->toDateString(),
            'note' => 'Standard rate.',
        ];
    }

    /**
     * A rate that has not started applying yet.
     */
    public function scheduled(string $percent = '18.00', int $daysAhead = 30): self
    {
        return $this->state(fn (): array => [
            'rate_percent' => $percent,
            'effective_from' => now()->addDays($daysAhead)->toDateString(),
        ]);
    }

    public function effectiveFrom(string $date, string $percent = '16.00'): self
    {
        return $this->state(fn (): array => [
            'rate_percent' => $percent,
            'effective_from' => $date,
        ]);
    }
}
