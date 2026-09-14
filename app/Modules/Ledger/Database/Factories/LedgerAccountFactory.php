<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Database\Factories;

use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Accounts are not really fixture data — the seeder writes the whole chart
 * and there are exactly ten of them. The factory exists so a test that needs
 * one account can ask for it by code without running the seeder.
 *
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    protected $model = LedgerAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->attributesFor($this->faker->randomElement(LedgerAccountCode::cases()));
    }

    public function code(LedgerAccountCode $code): static
    {
        return $this->state($this->attributesFor($code));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(LedgerAccountCode $code): array
    {
        return [
            'code' => $code,
            'name' => $code->label(),
            'type' => $code->type(),
            'normal_balance' => $code->normalBalance(),
            'subject' => $code->subject(),
            'description' => $code->description(),
        ];
    }
}
