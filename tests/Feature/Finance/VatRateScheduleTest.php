<?php

declare(strict_types=1);

use App\Modules\Finance\Database\Seeders\VatRateSeeder;
use App\Modules\Finance\Models\VatRate;
use App\Modules\Finance\Services\VatRateSchedule;
use App\Modules\Orders\Contracts\VatRateProvider;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Support\Roles\Role;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;

/**
 * A tax rate is a fact with a date on it.
 *
 * The rule these tests exist for: changing the rate must reprice FUTURE
 * orders and must not touch a single figure on one already settled. Snapshots
 * are what enforce that, and the last test here is the proof.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->schedule = app(VatRateSchedule::class);
});

it('binds the schedule over the settings floor', function (): void {
    expect(app(VatRateProvider::class))->toBeInstanceOf(VatRateSchedule::class);
});

it('answers with the rate in force on the day asked about', function (): void {
    VatRate::factory()->effectiveFrom('2026-01-01', '16.00')->create();
    VatRate::factory()->effectiveFrom('2026-06-01', '18.00')->create();

    expect($this->schedule->percentAt(CarbonImmutable::parse('2026-03-15')))->toBe('16.00')
        ->and($this->schedule->percentAt(CarbonImmutable::parse('2026-06-01')))->toBe('18.00')
        ->and($this->schedule->percentAt(CarbonImmutable::parse('2026-12-31')))->toBe('18.00');
});

it('falls back to the flat setting for a date before the schedule starts', function (): void {
    VatRate::factory()->effectiveFrom('2026-06-01', '18.00')->create();

    expect($this->schedule->percentAt(CarbonImmutable::parse('2025-01-01')))
        ->toBe((string) settings('monetisation.vat_on_commission_percent'));
});

it('does not apply a future rate to today', function (): void {
    VatRate::factory()->effectiveFrom(now()->subYear()->toDateString(), '16.00')->create();
    VatRate::factory()->scheduled('18.00', 30)->create();

    expect($this->schedule->percentAt())->toBe('16.00')
        ->and(MonetisationSnapshot::fromSettings()->vatOnCommissionPercent)->toBe('16.00');
});

it('amends a rate on a date already scheduled rather than duplicating it', function (): void {
    $date = CarbonImmutable::parse(now()->addDays(20)->toDateString());

    $this->schedule->schedule('18.00', $date);
    $this->schedule->schedule('17.50', $date);

    expect(VatRate::query()->whereDate('effective_from', $date->toDateString())->count())->toBe(1)
        ->and(VatRate::query()->whereDate('effective_from', $date->toDateString())->value('rate_percent'))
        ->toBe('17.50');
});

it('withdraws a scheduled rate but refuses one that has taken effect', function (): void {
    $future = VatRate::factory()->scheduled('18.00', 30)->create();
    $past = VatRate::factory()->effectiveFrom(now()->subMonth()->toDateString(), '16.00')->create();

    expect($this->schedule->withdraw($future))->toBeTrue()
        ->and($this->schedule->withdraw($past))->toBeFalse()
        ->and(VatRate::query()->whereKey($past->getKey())->exists())->toBeTrue();
});

it('mirrors today\'s rate onto the flat setting', function (): void {
    $this->schedule->schedule('19.00', CarbonImmutable::parse(now()->subDay()->toDateString()));

    expect((string) settings('monetisation.vat_on_commission_percent'))->toBe('19.00');
});

/**
 * The acceptance criterion, stated directly.
 */
it('leaves an already-settled order\'s snapshot untouched when the rate changes', function (): void {
    $this->seed(VatRateSeeder::class);

    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);

    $snapshotBefore = $order->monetisation_snapshot;
    $vatBefore = $order->monetisation()->vatOnCommission(money(100_000));

    /* ZRA moves the rate, effective yesterday. */
    $this->schedule->schedule('25.00', CarbonImmutable::parse(now()->subDay()->toDateString()));

    $order->refresh();

    expect($order->monetisation_snapshot)->toBe($snapshotBefore)
        ->and($order->monetisation()->vatOnCommission(money(100_000))->ngwee)->toBe($vatBefore->ngwee)
        /* ...while a NEW order would be priced at the new rate. */
        ->and(MonetisationSnapshot::fromSettings()->vatOnCommissionPercent)->toBe('25.00');
});

it('refuses a finance officer and allows a platform administrator', function (): void {
    $payload = [
        'rate_percent' => '18.00',
        'effective_from' => now()->addMonth()->toDateString(),
    ];

    actingAsStaff([Role::Finance]);
    $this->post(route('admin.finance.vat.store'), $payload)->assertForbidden();

    actingAsStaff([Role::PlatformAdmin]);
    $this->post(route('admin.finance.vat.store'), $payload)->assertRedirect();

    expect(VatRate::query()->where('rate_percent', '18.00')->exists())->toBeTrue();
});
