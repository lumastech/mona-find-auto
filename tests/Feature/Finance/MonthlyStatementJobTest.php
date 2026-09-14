<?php

declare(strict_types=1);

use App\Modules\Finance\Database\Seeders\VatRateSeeder;
use App\Modules\Finance\Jobs\GenerateMonthlyStatements;
use App\Modules\Finance\Models\SellerStatement;
use App\Modules\Finance\Services\SellerStatementService;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;

/**
 * The monthly close, run as the scheduler runs it.
 *
 * Two things are worth pinning down: it closes the month that has just ended
 * rather than the current one, and a single seller failing does not cost
 * every other seller their statement.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->seed(VatRateSeeder::class);

    $this->postings = app(OrderPostingService::class);
});

function tradedIn(OrderPostingService $postings, Seller $seller, Carbon $when, int $goods = 100_000): void
{
    Carbon::setTestNow($when);

    $order = Order::factory()->paid()->for($seller)->create([
        'items_total_ngwee' => $goods,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => $goods,
        'paid_at' => $when,
    ]);

    $postings->recordPayment($order);

    Carbon::setTestNow();
}

it('closes the month that has just ended, not the current one', function (): void {
    $seller = Seller::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00'));

    tradedIn($this->postings, $seller, Carbon::parse('2026-08-12 10:00:00'), 100_000);
    tradedIn($this->postings, $seller, Carbon::parse('2026-09-12 10:00:00'), 999_000);

    Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00'));

    (new GenerateMonthlyStatements)->handle(app(SellerStatementService::class));

    $statement = SellerStatement::query()->firstOrFail();

    expect($statement->period_year)->toBe(2026)
        ->and($statement->period_month)->toBe(8)
        /* September's much larger sale is deliberately not in it. */
        ->and($statement->sales_ngwee->ngwee)->toBe(100_000);

    Carbon::setTestNow();
});

it('closes a named month when one is given', function (): void {
    $seller = Seller::factory()->create();
    tradedIn($this->postings, $seller, Carbon::parse('2026-07-05 10:00:00'));

    (new GenerateMonthlyStatements('2026-07'))
        ->handle(app(SellerStatementService::class));

    expect(SellerStatement::query()->value('period_month'))->toBe(7);
});

it('writes nothing for a month in which nobody traded', function (): void {
    Seller::factory()->create();

    (new GenerateMonthlyStatements('2026-01'))
        ->handle(app(SellerStatementService::class));

    expect(SellerStatement::query()->count())->toBe(0);
});

it('is safe to run twice', function (): void {
    $seller = Seller::factory()->create();
    tradedIn($this->postings, $seller, Carbon::parse('2026-07-05 10:00:00'));

    $service = app(SellerStatementService::class);

    (new GenerateMonthlyStatements('2026-07'))->handle($service);
    (new GenerateMonthlyStatements('2026-07'))->handle($service);

    expect(SellerStatement::query()->count())->toBe(1);
});
