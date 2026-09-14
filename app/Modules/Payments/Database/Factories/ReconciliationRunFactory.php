<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Factories;

use App\Modules\Payments\Enums\ReconciliationStatus;
use App\Modules\Payments\Models\ReconciliationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationRun>
 */
class ReconciliationRunFactory extends Factory
{
    protected $model = ReconciliationRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'for_date' => now()->subDay()->toDateString(),
            'status' => ReconciliationStatus::Clean,
            'collections_checked' => 0,
            'settlements_checked' => 0,
            'transactions_checked' => 0,
            'exception_count' => 0,
            /*
             * Set explicitly even though the columns default to 0. A DB
             * default is applied by the database and is not reflected on the
             * model `create()` hands back, so a factory that left these out
             * produced an instance whose money accessors were null — which
             * the model's own @property annotations say they never are.
             */
            'gateway_total_ngwee' => 0,
            'ledger_total_ngwee' => 0,
            'variance_ngwee' => 0,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ];
    }

    public function withExceptions(int $count = 1): static
    {
        return $this->state(fn (): array => [
            'status' => ReconciliationStatus::Exceptions,
            'exception_count' => $count,
        ]);
    }
}
