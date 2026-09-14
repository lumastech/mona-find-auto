<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Support\Console\ConsoleChart;
use App\Support\Console\ConsoleStat;
use App\Support\Console\ConsoleStatDirection;
use App\Support\Console\ConsoleStatFormat;
use App\Support\Console\ConsoleStatistics;
use App\Support\Console\ConsoleWindow;
use App\Support\Console\ProvidesConsoleStatistics;
use App\Support\Roles\Role;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;

/**
 * The other half of the staff console: not what is waiting, but how it is going.
 *
 * The distinction these tests defend is the reason the strip exists at all.
 * Every figure belongs to the module that owns the definition behind it, the
 * money ones are ledger sums rather than order totals, and a role that cannot
 * act on a number never receives it — the same three rules the queue tiles
 * follow, applied to the numbers nobody clears.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
});

/**
 * The stat with the given key, or null.
 *
 * @param  array<int, array<string, mixed>>  $stats
 * @return array<string, mixed>|null
 */
function consoleStat(array $stats, string $key): ?array
{
    foreach ($stats as $entry) {
        if ($entry['key'] === $key) {
            return $entry;
        }
    }

    return null;
}

/**
 * The dashboard's deferred half, which the first response deliberately omits.
 *
 * This is the follow-up request the browser makes on its own once the page has
 * painted — a partial visit naming the deferred props. The asset version has
 * to be sent with it: Inertia answers a mismatched one with a 409 telling the
 * browser to reload, and a test that omitted it would be asserting about the
 * reload rather than about the statistics.
 *
 * @return array{stats: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>}
 */
function consoleInsights(): array
{
    $props = test()->get(route('admin.dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'admin/Dashboard',
        'X-Inertia-Partial-Data' => 'stats,charts',
    ])->assertOk()->json('props');

    return ['stats' => $props['stats'] ?? [], 'charts' => $props['charts'] ?? []];
}

/**
 * A Lusaka-local moment n days ago.
 *
 * Windows are Lusaka days, so a fixture stamped with a UTC "two days ago"
 * lands in the wrong bucket for two hours of every day — and the test that
 * fails is the one asserting a sparkline.
 */
function consoleDaysAgo(int $days): CarbonImmutable
{
    return CarbonImmutable::now((string) config('monafind.display_timezone'))
        ->subDays($days)
        ->setTime(9, 0);
}

it('leaves the statistics out of the first response so the queues are not held up', function (): void {
    actingAsStaff([Role::PlatformAdmin]);

    $props = $this->get(route('admin.dashboard'))->assertOk()->viewData('page')['props'];

    expect($props)->not->toHaveKey('stats')
        ->and($props)->not->toHaveKey('charts')
        ->and($props)->toHaveKey('counters')
        ->and($props['window']['days'])->toBe(30);
});

it('reports what the platform took, off the ledger', function (): void {
    actingAsStaff([Role::PlatformAdmin]);

    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 250_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 250_000,
    ]);

    app(OrderPostingService::class)->recordPayment($order);

    $gmv = consoleStat(consoleInsights()['stats'], 'finance.gmv');

    expect($gmv)->not->toBeNull()
        ->and($gmv['value'])->toBe(250_000)
        ->and($gmv['format'])->toBe('money')
        ->and($gmv['direction'])->toBe('higher_is_better');
});

it('counts orders placed in the window and the one before it', function (): void {
    actingAsStaff([Role::Moderator]);

    Order::factory()->count(3)->create(['created_at' => consoleDaysAgo(2)]);
    Order::factory()->count(5)->create(['created_at' => consoleDaysAgo(40)]);

    $placed = consoleStat(consoleInsights()['stats'], 'orders.placed');

    expect($placed['value'])->toBe(3)
        ->and($placed['previous'])->toBe(5)
        /* Down two fifths: -4000 hundredths of a percent. */
        ->and($placed['change'])->toBe(-4000);
});

it('gives every sparkline one point per day of the window', function (): void {
    actingAsStaff([Role::Moderator]);

    Order::factory()->count(2)->create(['created_at' => consoleDaysAgo(1)]);

    $placed = consoleStat(consoleInsights()['stats'], 'orders.placed');

    expect($placed['spark'])->toHaveCount(30)
        /* Yesterday is the second-to-last day of a window ending today. */
        ->and($placed['spark'][28])->toBe(2)
        ->and($placed['spark'][0])->toBe(0);
});

it('says nothing about a trend it cannot measure', function (): void {
    actingAsStaff([Role::Moderator]);

    Order::factory()->count(2)->create(['created_at' => consoleDaysAgo(1)]);

    $stats = consoleInsights()['stats'];

    /* Nothing happened in the window before, so "up 200%" would be a lie. */
    expect(consoleStat($stats, 'orders.placed')['change'])->toBeNull()
        /* A standing total is not a flow and carries no comparison at all. */
        ->and(consoleStat($stats, 'catalog.live')['previous'])->toBeNull();
});

it('reads catalogue growth off when a part became findable', function (): void {
    actingAsStaff([Role::Moderator]);

    Product::factory()->count(2)->create([
        'status' => ListingStatus::Published,
        'created_at' => consoleDaysAgo(50),
        'published_at' => consoleDaysAgo(3),
    ]);

    expect(consoleStat(consoleInsights()['stats'], 'catalog.published')['value'])->toBe(2);
});

it('counts a seller from the day it could start trading', function (): void {
    actingAsStaff([Role::Moderator]);

    Seller::factory()->count(2)->create([
        'verification_status' => VerificationStatus::Verified,
        'verified_at' => consoleDaysAgo(4),
    ]);
    Seller::factory()->create([
        'verification_status' => VerificationStatus::Submitted,
        'verified_at' => null,
    ]);

    $stats = consoleInsights()['stats'];

    expect(consoleStat($stats, 'sellers.verified')['value'])->toBe(2)
        ->and(consoleStat($stats, 'sellers.trading')['value'])->toBe(2);
});

it('states the dispute rate per thousand, where a percentage would round to nothing', function (): void {
    actingAsStaff([Role::Moderator]);

    Order::factory()->count(200)->create(['created_at' => consoleDaysAgo(3)]);
    OrderDispute::factory()->create(['created_at' => consoleDaysAgo(2)]);

    expect(consoleStat(consoleInsights()['stats'], 'orders.dispute_rate')['hint'])
        ->toBe('5 in every thousand orders placed.');
});

it('keeps the money off a moderator\'s console', function (): void {
    actingAsStaff([Role::Moderator]);

    $insights = consoleInsights();
    $keys = array_column($insights['stats'], 'key');

    expect($keys)->toContain('orders.placed', 'catalog.published', 'sellers.verified')
        ->and($keys)->not->toContain('finance.gmv')
        ->and($keys)->not->toContain('finance.escrow_held')
        ->and(array_column($insights['charts'], 'key'))->not->toContain('finance.trade');
});

it('keeps the moderation figures off a finance console', function (): void {
    actingAsStaff([Role::Finance]);

    $keys = array_column(consoleInsights()['stats'], 'key');

    expect($keys)->toContain('finance.gmv', 'finance.net_revenue')
        ->and($keys)->not->toContain('orders.placed')
        ->and($keys)->not->toContain('catalog.published');
});

it('draws no chart of a month in which nothing happened', function (): void {
    actingAsStaff([Role::PlatformAdmin]);

    expect(consoleInsights()['charts'])->toBe([]);
});

it('charts orders a day once there are some', function (): void {
    actingAsStaff([Role::Moderator]);

    Order::factory()->count(4)->create(['created_at' => consoleDaysAgo(5)]);

    $chart = consoleInsights()['charts'][0];

    expect($chart['key'])->toBe('orders.volume')
        ->and($chart['labels'])->toHaveCount(30)
        ->and($chart['series'][0]['values'])->toHaveCount(30)
        ->and(array_sum($chart['series'][0]['values']))->toBe(4);
});

it('survives a module whose statistics throw', function (): void {
    actingAsStaff([Role::PlatformAdmin]);

    app(ConsoleStatistics::class)->register(BrokenStatistics::class);

    Order::factory()->count(2)->create(['created_at' => consoleDaysAgo(1)]);

    /* The broken module costs its own tiles and nothing else. */
    $keys = array_column(consoleInsights()['stats'], 'key');

    expect($keys)->toContain('orders.placed')
        ->and($keys)->not->toContain('broken.stat');
});

it('compares a window against the same number of days, never a calendar month', function (): void {
    $window = ConsoleWindow::lastDays(30);
    $previous = $window->previous();

    expect($previous->days())->toBe(30)
        ->and($previous->to->toDateString())->toBe($window->from->subDay()->toDateString());
});

class BrokenStatistics implements ProvidesConsoleStatistics
{
    public function stats(ConsoleWindow $window): array
    {
        throw new RuntimeException('The metrics warehouse is on fire.');
    }

    public function charts(ConsoleWindow $window): array
    {
        return [
            new ConsoleChart(
                key: 'broken.chart',
                title: 'Never drawn',
                labels: [],
                series: [],
            ),
        ];
    }
}

it('compares like with like when a stat says which way is up', function (): void {
    $improving = new ConsoleStat(
        key: 'test.stat',
        label: 'Test',
        value: 150,
        previous: 100,
        format: ConsoleStatFormat::Count,
        direction: ConsoleStatDirection::HigherIsBetter,
    );

    expect($improving->changeInBasisPoints())->toBe(5000)
        /* Rounded half up, and never through a float. */
        ->and((new ConsoleStat('k', 'l', 1, 3))->changeInBasisPoints())->toBe(-6667)
        ->and((new ConsoleStat('k', 'l', 5, 0))->changeInBasisPoints())->toBeNull()
        ->and((new ConsoleStat('k', 'l', 5))->changeInBasisPoints())->toBeNull();
});
