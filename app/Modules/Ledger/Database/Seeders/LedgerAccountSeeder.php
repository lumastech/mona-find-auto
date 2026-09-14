<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Database\Seeders;

use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Models\LedgerAccount;
use Illuminate\Database\Seeder;

/**
 * Copies the chart of accounts out of LedgerAccountCode and into the table.
 *
 * Infrastructure rather than fixture data: a deployment without these rows
 * cannot post a single entry, so this runs on every environment and is safe
 * to re-run. Names and descriptions are refreshed; nothing else about an
 * account can change, because nothing else about an account is stored.
 */
class LedgerAccountSeeder extends Seeder
{
    public function run(): void
    {
        foreach (LedgerAccountCode::cases() as $code) {
            LedgerAccount::query()->updateOrCreate(
                ['code' => $code->value],
                [
                    'name' => $code->label(),
                    'type' => $code->type(),
                    'normal_balance' => $code->normalBalance(),
                    'subject' => $code->subject(),
                    'description' => $code->description(),
                ],
            );
        }

        LedgerAccount::forgetCache();
    }
}
