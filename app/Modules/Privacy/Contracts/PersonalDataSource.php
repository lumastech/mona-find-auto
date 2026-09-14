<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Contracts;

use App\Models\User;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataSection;

/**
 * A module's own answer to the two questions the Data Protection Act asks.
 *
 * Privacy does not know what personal data lives in Orders, or which of
 * Messaging's columns hold a phone number. Asking it to would mean one module
 * reaching into thirteen others' tables and going stale the first time any of
 * them added a column.
 *
 * So the question is turned around. Each module implements this interface for
 * the data it owns and registers it with `PersonalDataRegistry` from its own
 * service provider. Privacy orchestrates; it never reads another module's
 * models directly.
 *
 * ## The contract between the two methods
 *
 * `export()` and `erase()` must cover the same ground. Anything a module puts
 * in its export is personal data by its own admission, and must therefore be
 * gone — or defensibly retained — after `erase()`. The test
 * `tests/Feature/Privacy/AccountErasureTest.php` holds every source to that.
 *
 * ## What erase() must not do
 *
 * Never delete a financial or legal record. Ledger entries, payments, orders,
 * audit rows and terms acceptances are retained under the Act's legal-obligation
 * basis and several of them are append-only at the database level, so an
 * attempt would fail loudly anyway. Strip the PII *within* what has to stay,
 * and leave the amounts, timestamps and references alone.
 */
interface PersonalDataSource
{
    /**
     * A stable key for this source, used as the top-level key in the export
     * file and in the erasure report. Lowercase, dot-free: "orders", "messaging".
     */
    public function key(): string;

    /**
     * Everything this module holds about `$user`, in a form a person can read.
     *
     * Return an empty array when the module holds nothing for this account —
     * the exporter omits empty sections rather than filling the file with
     * headings for things that never happened.
     *
     * @return array<int, PersonalDataSection>
     */
    public function export(User $user): array;

    /**
     * Remove or anonymise this module's personal data for `$user`.
     *
     * Return a per-table tally of what was done, which becomes part of the
     * immutable erasure record: ['wishlist_items' => 12, 'carts' => 1].
     *
     * @return array<string, int>
     */
    public function erase(User $user, Anonymiser $anonymiser): array;
}
