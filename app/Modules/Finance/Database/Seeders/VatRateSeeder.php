<?php

declare(strict_types=1);

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Finance\Models\VatRate;
use Illuminate\Database\Seeder;

/**
 * Opens the VAT schedule with whatever the flat setting already says.
 *
 * The platform ran on `monetisation.vat_on_commission_percent` before the
 * schedule existed, and orders settled under it. Seeding the opening row from
 * that setting — dated far enough back to cover every order the platform has
 * ever taken — means the schedule agrees with history rather than starting a
 * fresh story from today.
 *
 * The date is the reason this is not just "insert today's rate". A statement
 * or an invoice query about last year has to resolve to a rate, and a
 * schedule whose earliest row is dated this morning answers every older
 * question with the settings fallback instead. One row, dated 2020, removes
 * that whole class of near-miss.
 *
 * Idempotent: an existing schedule is left alone, so re-seeding a live
 * database cannot overwrite rates an administrator has entered.
 */
class VatRateSeeder extends Seeder
{
    public function run(): void
    {
        if (VatRate::query()->exists()) {
            return;
        }

        VatRate::query()->create([
            'rate_percent' => (string) settings('monetisation.vat_on_commission_percent', '16.00'),
            /* Comfortably before the platform took its first order. */
            'effective_from' => '2020-01-01',
            'note' => 'Opening rate, carried over from the platform settings.',
        ]);
    }
}
