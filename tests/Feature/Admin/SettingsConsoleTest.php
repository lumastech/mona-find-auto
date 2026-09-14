<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
});

/**
 * @param  array<string, mixed>  $values
 */
function saveSettings(array $values, string $panel = 'escrow', string $reason = 'Agreed at the Monday operations meeting.'): TestResponse
{
    return test()->put(route('admin.settings.update'), [
        'panel' => $panel,
        'reason' => $reason,
        'values' => $values,
    ]);
}

it('shows every panel with its current values', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $this->get(route('admin.settings.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $panels = $page->toArray()['props']['panels'];
            $keys = array_column($panels, 'key');

            expect($keys)->toContain('escrow', 'freshness', 'ranking', 'monetisation', 'risk', 'payouts', 'policies');

            $escrow = $panels[array_search('escrow', $keys, true)];
            $pickup = collect($escrow['fields'])->firstWhere('key', 'escrow.pickup_window_days');

            expect($pickup['value'])->toBe(3)
                ->and($pickup['min'])->toBe(1)
                ->and($pickup['max'])->toBe(30);

            return $page->component('admin/settings/Index');
        });
});

it('saves a value and records why', function () {
    $admin = actingAsStaff([Role::PlatformAdmin]);

    saveSettings(['escrow.pickup_window_days' => 5], reason: 'Buyers in Solwezi need longer to collect.')
        ->assertRedirect();

    expect(settings('escrow.pickup_window_days'))->toBe(5);

    $entry = AuditLog::query()->where('action', 'setting.updated')->latest('id')->first();

    expect($entry->actor_id)->toBe($admin->id)
        ->and($entry->reason)->toBe('Buyers in Solwezi need longer to collect.')
        ->and($entry->before)->toBe(['key' => 'escrow.pickup_window_days', 'value' => '3']);
});

it('refuses a value outside its range', function (string $key, mixed $value) {
    actingAsStaff([Role::PlatformAdmin]);

    $before = settings($key);

    saveSettings([$key => $value])->assertSessionHasErrors('values.'.$key);

    expect(settings($key))->toBe($before);
})->with([
    'escrow window of zero days' => ['escrow.pickup_window_days', 0],
    'escrow window of a year' => ['escrow.pickup_window_days', 365],
    'a ranking weight above 100' => ['ranking.weight.seller_rating', 900],
    'a negative ranking weight' => ['ranking.weight.seller_rating', -5],
    'commission above 40%' => ['monetisation.commission_percent', '65.00'],
    'a reserve above half' => ['risk.reserve_percent', '80.00'],
    'a confirmation window over a week' => ['orders.seller_confirm_window_hours', 400],
    'a payment mode that does not exist' => ['escrow.default_payment_mode', 'whenever'],
    'a fee bearer that does not exist' => ['payments.fee_bearer', 'somebody-else'],
    'a refund statement of two words' => ['policies.minimum_refund_statement', 'No refunds'],
]);

it('refuses freshness windows that make a state unreachable', function () {
    actingAsStaff([Role::PlatformAdmin]);

    /* Ageing must end after Fresh does, or nothing is ever Ageing. */
    saveSettings([
        'freshness.fresh_max_days' => 5,
        'freshness.ageing_max_days' => 3,
        'freshness.hidden_after_days' => 14,
    ], panel: 'freshness')->assertSessionHasErrors('values.freshness.ageing_max_days');

    expect(settings('freshness.fresh_max_days'))->toBe(3);
});

it('checks one window against the two already stored beside it', function () {
    actingAsStaff([Role::PlatformAdmin]);

    /* Fresh alone, pushed past the stored Ageing window of 5. */
    saveSettings(['freshness.fresh_max_days' => 9], panel: 'freshness')
        ->assertSessionHasErrors('values.freshness.fresh_max_days');

    expect(settings('freshness.fresh_max_days'))->toBe(3);
});

it('accepts a widened set of freshness windows', function () {
    actingAsStaff([Role::PlatformAdmin]);

    saveSettings([
        'freshness.fresh_max_days' => 4,
        'freshness.ageing_max_days' => 7,
        'freshness.hidden_after_days' => 21,
    ], panel: 'freshness')->assertSessionHasNoErrors();

    expect(settings('freshness.ageing_max_days'))->toBe(7);
});

it('requires a reason for every change', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $this->put(route('admin.settings.update'), [
        'panel' => 'escrow',
        'values' => ['escrow.pickup_window_days' => 5],
    ])->assertSessionHasErrors('reason');

    expect(settings('escrow.pickup_window_days'))->toBe(3);
});

it('does not write an audit row when nothing actually changed', function () {
    actingAsStaff([Role::PlatformAdmin]);

    saveSettings(['escrow.pickup_window_days' => 3])->assertRedirect();

    expect(AuditLog::query()->where('action', 'setting.updated')->count())->toBe(0);
});

it('will not write the platform terms from this screen', function () {
    actingAsStaff([Role::PlatformAdmin]);

    saveSettings([
        'policies.platform_terms_version' => '99',
        'policies.platform_terms_body' => 'Anything at all.',
    ], panel: 'policies')->assertRedirect();

    /* Owned by the CMS page, whose publication is what bumps the version. */
    expect(settings('policies.platform_terms_version'))->toBe('1');
});

it('keeps a moderator out of the settings screen entirely', function () {
    actingAsStaff([Role::Moderator]);

    $this->get(route('admin.settings.index'))->assertForbidden();
    saveSettings(['escrow.pickup_window_days' => 5])->assertForbidden();
});

it('keeps finance out of the settings screen', function () {
    actingAsStaff([Role::Finance]);

    $this->get(route('admin.settings.index'))->assertForbidden();
});
