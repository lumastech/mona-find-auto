<?php

declare(strict_types=1);

use App\Modules\Finance\Database\Seeders\VatRateSeeder;
use App\Modules\Finance\Enums\FinanceExport;
use App\Modules\Finance\Services\FinanceExportService;
use App\Modules\Finance\Support\MetricWindow;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;

/**
 * The hand-off to the accountant.
 *
 * These files are the only route by which the platform's books leave it —
 * there is no accounting integration behind MonaFind — so the things worth
 * testing are that the figures are readable by a person, that the journal
 * still foots, and that taking one is recorded.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->seed(VatRateSeeder::class);

    $this->postings = app(OrderPostingService::class);
    $this->exports = app(FinanceExportService::class);
    $this->window = MetricWindow::fromStrings(null, null);
});

function exportedOrder(OrderPostingService $postings, int $goods = 100_000): Order
{
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => $goods,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => $goods,
    ]);

    $postings->recordPayment($order);
    $postings->releaseEscrow($order);

    return $order;
}

it('writes money in kwacha rather than ngwee', function (): void {
    exportedOrder($this->postings, 155_000);

    $csv = $this->exports->build(FinanceExport::Orders, $this->window, 'csv');

    expect($csv)->toContain('1550.00')
        ->and($csv)->not->toContain('155000');
});

it('foots the journal export: debits equal credits', function (): void {
    exportedOrder($this->postings);

    $csv = $this->exports->build(FinanceExport::Journal, $this->window, 'csv');
    $rows = array_slice(array_filter(explode("\n", trim($csv))), 1);

    $debits = 0.0;
    $credits = 0.0;

    foreach ($rows as $row) {
        $cells = str_getcsv($row, ',', '"', '\\');

        /* Columns 6 and 7 are the debit and credit amounts. */
        $debits += (float) ($cells[6] ?: 0);
        $credits += (float) ($cells[7] ?: 0);
    }

    expect($debits)->toBeGreaterThan(0.0)
        ->and(round($debits, 2))->toBe(round($credits, 2));
});

it('produces one row per journal line, not per entry', function (): void {
    exportedOrder($this->postings);

    $csv = $this->exports->build(FinanceExport::Journal, $this->window, 'csv');
    $lines = count(array_filter(explode("\n", trim($csv)))) - 1;

    /* An escrow payment posts two lines and a release posts several more. */
    expect($lines)->toBeGreaterThan(2);
});

it('builds an XLSX a spreadsheet will open', function (): void {
    exportedOrder($this->postings);

    $xlsx = $this->exports->build(FinanceExport::Payouts, $this->window, 'xlsx');

    /* The ZIP local-file-header magic every OOXML file starts with. */
    expect(substr($xlsx, 0, 2))->toBe('PK');
});

it('names the file after the export and its window', function (): void {
    $name = $this->exports->filename(FinanceExport::Journal, $this->window, 'csv');

    expect($name)->toContain('journal')
        ->toContain($this->window->from->toDateString())
        ->toEndWith('.csv');
});

describe('the console screen', function (): void {
    it('records who took an export off the platform', function (): void {
        exportedOrder($this->postings);
        actingAsStaff([Role::Finance]);

        $this->get(route('admin.finance.exports.download', ['export' => 'journal']))
            ->assertOk()
            ->assertDownload();

        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.export.downloaded']);
    });

    it('is closed to a moderator', function (): void {
        actingAsStaff([Role::Moderator]);

        $this->get(route('admin.finance.exports.index'))->assertForbidden();
        $this->get(route('admin.finance.exports.download', ['export' => 'orders']))
            ->assertForbidden();
    });

    it('404s on an export nobody defined', function (): void {
        actingAsStaff([Role::Finance]);

        $this->get(route('admin.finance.exports.download', ['export' => 'everything']))
            ->assertNotFound();
    });
});
