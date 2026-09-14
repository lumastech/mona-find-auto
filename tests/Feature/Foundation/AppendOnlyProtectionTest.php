<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Privacy\Models\ConsentRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The append-only guarantee has two layers and this asserts the second one.
 *
 * The `AppendOnly` trait fails loudly in application code. The database
 * triggers are what stop a raw `DELETE` — from a console, a migration, or a
 * restored backup taken without `--routines --triggers`.
 *
 * The trait is easy to remember and the trigger is easy to forget, because a
 * model missing its trigger behaves correctly in every test that goes through
 * Eloquent. This is the test that notices.
 */

/**
 * Every table that must refuse UPDATE and DELETE at the database level.
 *
 * @return array<int, string>
 */
function appendOnlyTables(): array
{
    return [
        'audit_logs',
        'consent_records',
        'journal_entries',
        'journal_lines',
        'order_status_events',
        'payments',
        'stock_movements',
        'terms_acceptances',
    ];
}

it('installs both triggers on every append-only table', function (string $table): void {
    $triggers = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [$table]))
        ->pluck('name')
        ->all();

    expect($triggers)->toContain("{$table}_no_update", "{$table}_no_delete");
})->with(appendOnlyTables());

it('protects exactly the tables the backup drill expects', function (): void {
    $count = count(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger'"));

    /*
     * Two triggers per table. `scripts/restore-drill.sh` fails a restore that
     * brings back fewer than this, because a dump taken without
     * `--routines --triggers` produces a database that looks complete and has
     * an editable ledger.
     *
     * If this number goes UP because a new append-only table was added, raise
     * the figure here and in docs/BACKUP_AND_RECOVERY.md. If it goes DOWN,
     * something has lost its protection.
     */
    expect($count)->toBe(count(appendOnlyTables()) * 2)
        ->and($count)->toBe(16);
});

it('refuses a raw delete that never touches Eloquent', function (): void {
    $log = AuditLog::query()->create([
        'action' => 'test.performed',
        'context' => [],
    ]);

    /*
     * Straight to the query builder, past the model events entirely. This is
     * the path a console session or a careless migration would take.
     */
    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->delete())
        ->toThrow(QueryException::class, 'append-only');

    expect(AuditLog::query()->whereKey($log->id)->exists())->toBeTrue();
});

it('refuses a raw update that never touches Eloquent', function (): void {
    $record = ConsentRecord::factory()->create(['granted' => true]);

    expect(fn () => DB::table('consent_records')->where('id', $record->id)->update(['granted' => false]))
        ->toThrow(QueryException::class, 'append-only');

    expect(ConsentRecord::query()->whereKey($record->id)->sole()->granted)->toBeTrue();
});
