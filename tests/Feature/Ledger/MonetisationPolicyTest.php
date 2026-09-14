<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Ledger\Enums\CommissionType;
use App\Modules\Ledger\Models\MonetisationPolicy;
use App\Modules\Ledger\Services\LedgerMonetisationPolicyProvider;
use App\Modules\Ledger\Services\MonetisationPolicyService;
use App\Modules\Orders\Actions\SnapshotOrderTerms;
use App\Modules\Orders\Contracts\MonetisationPolicyProvider;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->service = app(MonetisationPolicyService::class);
    $this->provider = app(MonetisationPolicyProvider::class);
});

it('is the provider Orders resolves', function (): void {
    expect($this->provider)->toBeInstanceOf(LedgerMonetisationPolicyProvider::class);
});

it('falls back from the seller to the default to the settings', function (): void {
    $seller = Seller::factory()->create();

    /* No policy rows at all: the platform settings still price the order. */
    expect($this->provider->forSeller($seller)->commissionPercent)->toBe('7.50');

    $default = MonetisationPolicy::factory()->default()->percentage('5.00')->create();
    expect($this->provider->forSeller($seller->fresh())->commissionPercent)->toBe('5.00');

    $bespoke = MonetisationPolicy::factory()->percentage('3.25')->create();
    $this->service->assign($seller, $bespoke);

    expect($this->provider->forSeller($seller->fresh())->commissionPercent)->toBe('3.25')
        ->and($default->fresh()->is_default)->toBeTrue();
});

it('reads VAT and the reserve from settings, never from a policy', function (): void {
    $policy = MonetisationPolicy::factory()->default()->create();

    $snapshot = $policy->toSnapshot();

    expect($snapshot->vatOnCommissionPercent)->toBe('16.00')
        ->and($snapshot->reservePercent)->toBe('10.00');
});

it('keeps exactly one default', function (): void {
    $first = MonetisationPolicy::factory()->default()->create();
    $second = MonetisationPolicy::factory()->create();

    $this->service->makeDefault($second);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and(MonetisationPolicy::query()->where('is_default', true)->count())->toBe(1);
});

it('audits an assignment on both sides', function (): void {
    $actor = User::factory()->create();
    $seller = Seller::factory()->create();
    $policy = MonetisationPolicy::factory()->create();

    $this->service->assign($seller, $policy, $actor, 'Negotiated at launch.');

    $audit = AuditLog::query()->where('action', 'monetisation_policy.assigned')->sole();

    expect($audit->before['monetisation_policy_id'])->toBeNull()
        ->and($audit->after['policy'])->toBe($policy->slug)
        ->and($audit->reason)->toBe('Negotiated at launch.')
        ->and($audit->actor_id)->toBe($actor->getKey());
});

it('retires rather than deletes, and refuses to retire the default', function (): void {
    $default = MonetisationPolicy::factory()->default()->create();
    $other = MonetisationPolicy::factory()->create();

    $this->service->deactivate($other);
    expect($other->fresh()->is_active)->toBeFalse();

    $this->service->deactivate($default);
    expect($default->fresh()->is_active)->toBeTrue();
});

it('keeps sellers on a policy that has been retired', function (): void {
    MonetisationPolicy::factory()->default()->percentage('9.00')->create();
    $legacy = MonetisationPolicy::factory()->percentage('2.00')->create();
    $seller = Seller::factory()->create();

    $this->service->assign($seller, $legacy);
    $this->service->deactivate($legacy);

    /* Retiring takes it off the list of choices; it does not reprice the shop. */
    expect($this->provider->forSeller($seller->fresh())->commissionPercent)->toBe('2.00');
});

it('does not rename the slug when a policy is renamed', function (): void {
    $policy = MonetisationPolicy::factory()->create(['name' => 'Launch terms', 'slug' => 'launch-terms']);

    $this->service->update($policy, [
        'name' => 'Legacy launch terms',
        'commission_type' => CommissionType::Percentage,
        'commission_percent' => '4.00',
    ]);

    expect($policy->fresh()->slug)->toBe('launch-terms')
        ->and($policy->fresh()->name)->toBe('Legacy launch terms');
});

/**
 * The rule the whole snapshot exists for.
 */
it('leaves an order\'s snapshot untouched when the policy is edited afterwards', function (): void {
    $policy = MonetisationPolicy::factory()->default()->percentage('5.00')->create();
    $seller = Seller::factory()->create();
    $order = Order::factory()->for($seller)->create();

    app(SnapshotOrderTerms::class)->apply($order);
    $order->save();

    expect($order->monetisation()->commissionPercent)->toBe('5.00');

    /* An administrator doubles the commission the following week. */
    $this->service->update($policy, [
        'name' => $policy->name,
        'commission_type' => CommissionType::Percentage,
        'commission_percent' => '10.00',
    ]);

    expect($policy->fresh()->commission_percent)->toBe('10.00')
        /* Wednesday's sale is untouched. */
        ->and($order->fresh()->monetisation()->commissionPercent)->toBe('5.00')
        /* And so is what it earned. */
        ->and($order->fresh()->commission())->toBeMoney(
            (int) round($order->items_total_ngwee->ngwee * 0.05),
        );
});

it('leaves the snapshot untouched when the seller is moved to another policy', function (): void {
    MonetisationPolicy::factory()->default()->percentage('5.00')->create();
    $seller = Seller::factory()->create();
    $order = Order::factory()->for($seller)->create();

    app(SnapshotOrderTerms::class)->apply($order);
    $order->save();

    $this->service->assign($seller, MonetisationPolicy::factory()->percentage('20.00')->create());

    expect($order->fresh()->monetisation()->commissionPercent)->toBe('5.00');
});
