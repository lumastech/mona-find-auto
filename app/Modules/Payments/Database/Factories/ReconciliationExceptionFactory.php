<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Factories;

use App\Modules\Payments\Enums\ReconciliationExceptionType;
use App\Modules\Payments\Models\ReconciliationException;
use App\Modules\Payments\Models\ReconciliationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationException>
 */
class ReconciliationExceptionFactory extends Factory
{
    protected $model = ReconciliationException::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = ReconciliationExceptionType::Missing;

        return [
            'reconciliation_run_id' => ReconciliationRun::factory(),
            'type' => $type,
            'severity' => $type->severity(),
            'reference' => 'MFA-'.strtoupper($this->faker->bothify('??######')).'-1',
            'detail' => 'Lenco collected this payment but MonaFind has no record of it.',
        ];
    }
}
