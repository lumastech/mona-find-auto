<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Settings\SettingsRepository;
use App\Support\Settings\SettingType;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
});

it('seeds the platform defaults the brief calls for', function (string $key, mixed $expected) {
    expect(settings($key))->toBe($expected);
})->with([
    ['escrow.pickup_window_days', 3],
    ['escrow.delivery_window_days', 7],
    ['freshness.fresh_max_days', 3],
    ['freshness.ageing_max_days', 5],
    ['freshness.hidden_after_days', 14],
    ['ranking.weight.seller_rating', 30],
    ['ranking.weight.review_count', 10],
    ['ranking.weight.verified_seller', 20],
    ['ranking.weight.inspected', 15],
    ['ranking.weight.freshness', 15],
    ['ranking.weight.low_disputes', 10],
    ['risk.reserve_percent', '10.00'],
    ['risk.dispute_rate_threshold_percent', '2.00'],
    ['orders.seller_confirm_window_hours', 24],
]);

it('returns the default for a key that does not exist', function () {
    expect(settings('nope.not.here', 'fallback'))->toBe('fallback');
});

it('returns the repository when called with no key', function () {
    expect(settings())->toBeInstanceOf(SettingsRepository::class);
});

it('decodes each stored type', function () {
    $settings = app(SettingsRepository::class);

    $settings->define('test.flag', true, ['type' => SettingType::Boolean]);
    $settings->define('test.amount', '12.50', ['type' => SettingType::Money]);
    $settings->define('test.weights', ['a' => 1], ['type' => SettingType::Array]);
    $settings->define('test.rate', '7.50', ['type' => SettingType::Decimal]);

    expect($settings->boolean('test.flag'))->toBeTrue()
        ->and($settings->money('test.amount'))->toBeInstanceOf(Money::class)
        ->and($settings->money('test.amount')->ngwee)->toBe(1250)
        ->and($settings->array('test.weights'))->toBe(['a' => 1])
        ->and($settings->string('test.rate'))->toBe('7.50')
        ->and($settings->integer('escrow.pickup_window_days'))->toBe(3);
});

it('caches reads and refreshes them when a value changes', function () {
    $settings = app(SettingsRepository::class);

    expect($settings->get('escrow.pickup_window_days'))->toBe(3)
        ->and(Cache::has(SettingsRepository::CACHE_KEY))->toBeTrue();

    $settings->set('escrow.pickup_window_days', 5);

    expect($settings->get('escrow.pickup_window_days'))->toBe(5);
});

it('still serves the cached snapshot when the table changes behind its back', function () {
    $settings = app(SettingsRepository::class);

    expect($settings->get('escrow.pickup_window_days'))->toBe(3);

    /** A write that goes around the repository leaves the cache stale... */
    Setting::query()->where('key', 'escrow.pickup_window_days')->update(['value' => '9']);

    expect($settings->get('escrow.pickup_window_days'))->toBe(3);

    /** ...until the cache is cleared. */
    $settings->flush();

    expect($settings->get('escrow.pickup_window_days'))->toBe(9);
});

it('audits every change with the value before and after', function () {
    $admin = User::factory()->create();

    settings()->set('escrow.delivery_window_days', 10, $admin, 'Longer window over the holidays.');

    $entry = AuditLog::query()->where('action', 'setting.updated')->sole();

    expect($entry->actor_id)->toBe($admin->id)
        ->and($entry->before)->toBe(['key' => 'escrow.delivery_window_days', 'value' => '7'])
        ->and($entry->after)->toBe(['key' => 'escrow.delivery_window_days', 'value' => '10'])
        ->and($entry->reason)->toBe('Longer window over the holidays.');
});

it('exposes only the settings marked public', function () {
    $public = app(SettingsRepository::class)->publicValues();

    expect($public)->toHaveKey('escrow.pickup_window_days')
        ->and($public)->not->toHaveKey('monetisation.commission_percent');
});

it('groups settings for the admin console', function () {
    /* Named rather than counted: a count breaks whenever a group grows. */
    expect(array_keys(app(SettingsRepository::class)->group('freshness')))
        ->toEqualCanonicalizing([
            'freshness.fresh_max_days',
            'freshness.ageing_max_days',
            'freshness.hidden_after_days',
            'freshness.reminder_days',
        ]);
});

it('keeps an administrator\'s value when the seeder is re-run', function () {
    settings()->set('escrow.pickup_window_days', 4);

    $this->seed(SettingsSeeder::class);

    expect(settings('escrow.pickup_window_days'))->toBe(4);
});
