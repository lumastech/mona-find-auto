<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Database\Factories;

use App\Models\User;
use App\Modules\Ledger\Enums\AdjustmentStatus;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Models\LedgerAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A drafted adjustment — pending, and therefore having moved nothing.
 *
 * There is no "approved" state, because approving is what posts: an approved
 * adjustment with no journal entry behind it would be a row claiming money
 * moved when it did not. Tests that need one call
 * LedgerAdjustmentService::approve().
 *
 * @extends Factory<LedgerAdjustment>
 */
class LedgerAdjustmentFactory extends Factory
{
    protected $model = LedgerAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = $this->faker->numberBetween(1_000, 100_000);

        return [
            'status' => AdjustmentStatus::Pending,
            'description' => 'Write off an unrecoverable gateway shortfall',
            'reason' => $this->faker->sentence(),
            'lines' => [
                [
                    'account' => LedgerAccountCode::RefundsExpense->value,
                    'direction' => EntryDirection::Debit->value,
                    'amount_ngwee' => $amount,
                    'subject_type' => null,
                    'subject_id' => null,
                    'memo' => null,
                ],
                [
                    'account' => LedgerAccountCode::VatOnCommissionPayable->value,
                    'direction' => EntryDirection::Credit->value,
                    'amount_ngwee' => $amount,
                    'subject_type' => null,
                    'subject_id' => null,
                    'memo' => null,
                ],
            ],
            'total_ngwee' => $amount,
            'created_by' => User::factory(),
        ];
    }
}
