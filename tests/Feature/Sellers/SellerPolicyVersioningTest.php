<?php

declare(strict_types=1);

use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerPolicy;
use App\Modules\Sellers\Services\SellerPolicyService;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->policies = app(SellerPolicyService::class);
});

it('publishes the first version of a policy', function () {
    $seller = Seller::factory()->create();

    $policy = $this->policies->publish($seller, PolicyType::Refund, 'Wrong parts come back within seven days.');

    expect($policy->version)->toBe(1)
        ->and($policy->is_current)->toBeTrue()
        ->and($seller->currentPolicy(PolicyType::Refund)->is($policy))->toBeTrue();
});

it('keeps the old version when a policy is edited', function () {
    $seller = Seller::factory()->create();

    $first = $this->policies->publish($seller, PolicyType::Refund, 'Wrong parts come back within seven days.');
    $second = $this->policies->publish($seller, PolicyType::Refund, 'Wrong parts come back within fourteen days.');

    expect($second->version)->toBe(2)
        ->and($second->is_current)->toBeTrue()
        ->and($first->refresh()->is_current)->toBeFalse()
        /* The text a buyer accepted has to stay readable, whatever the seller writes next. */
        ->and($first->refresh()->body)->toBe('Wrong parts come back within seven days.')
        ->and($seller->policies()->where('type', PolicyType::Refund)->count())->toBe(2);
});

it('versions each policy type independently', function () {
    $seller = Seller::factory()->create();

    $this->policies->publish($seller, PolicyType::Refund, 'Refunds within seven days of delivery.');
    $this->policies->publish($seller, PolicyType::Refund, 'Refunds within fourteen days of delivery.');
    $delivery = $this->policies->publish($seller, PolicyType::Delivery, 'We deliver across Lusaka in two days.');

    expect($delivery->version)->toBe(1)
        ->and($seller->currentPolicy(PolicyType::Refund)->version)->toBe(2);
});

it('does not bump the version when nothing changed', function () {
    $seller = Seller::factory()->create();
    $body = 'Wrong parts come back within seven days of delivery.';

    $first = $this->policies->publish($seller, PolicyType::Warranty, $body);
    $again = $this->policies->publish($seller, PolicyType::Warranty, $body);

    expect($again->is($first))->toBeTrue()
        ->and($seller->policies()->where('type', PolicyType::Warranty)->count())->toBe(1);
});

it('leaves exactly one current version per type', function () {
    $seller = Seller::factory()->create();

    foreach (range(1, 4) as $round) {
        $this->policies->publish($seller, PolicyType::Terms, "Version {$round} of our terms of sale for buyers.");
    }

    $current = $seller->policies()->where('type', PolicyType::Terms)->where('is_current', true)->get();

    expect($current)->toHaveCount(1)
        ->and($current->first()->version)->toBe(4);
});

it('records who published a version and audits the change', function () {
    $seller = Seller::factory()->create();
    $author = $seller->user;

    $policy = $this->policies->publish($seller, PolicyType::Delivery, 'We deliver across Lusaka within two days.', $author);

    expect($policy->created_by)->toBe($author->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'seller.policy.published',
        'subject_type' => $policy->getMorphClass(),
        'subject_id' => $policy->id,
    ]);
});

it('shows the platform refund floor alongside the seller policy', function () {
    $this->seed(SettingsSeeder::class);

    $minimum = $this->policies->platformMinimumRefund();

    expect($minimum['days'])->toBe(3)
        ->and($minimum['statement'])->toContain('3 days');
});

it('falls back to a stated floor when the setting has never been seeded', function () {
    $minimum = $this->policies->platformMinimumRefund();

    expect($minimum['days'])->toBe(3)
        ->and($minimum['statement'])->toContain('wrong or damaged item');
});

it('reports which required policies a seller is still missing', function () {
    $seller = Seller::factory()->create();

    expect($seller->missingPolicies())->toBe(PolicyType::required());

    $this->policies->publish($seller, PolicyType::Delivery, 'We deliver across Lusaka within two days.');
    $this->policies->publish($seller, PolicyType::Refund, 'Wrong parts come back within seven days.');

    expect($seller->refresh()->missingPolicies())->toBe([PolicyType::Warranty]);
});

it('treats a policy dated in the future as not yet in force', function () {
    $seller = Seller::factory()->create();

    $policy = SellerPolicy::factory()->for($seller)->effectiveFrom(now()->addWeek()->toDateTimeString())->create();

    expect($policy->is_current)->toBeTrue()
        ->and($policy->isInForce())->toBeFalse();
});

it('lets a seller publish a new version through the portal', function () {
    $seller = Seller::factory()->create();

    $this->actingAs($seller->user)
        ->post(route('seller.policies.store', ['type' => PolicyType::Refund->value]), [
            'body' => 'Wrong or damaged parts come back within seven days for a full refund.',
        ])
        ->assertRedirect(route('seller.policies.index'));

    expect($seller->currentPolicy(PolicyType::Refund)->version)->toBe(1);
});

it('will not let one seller publish a policy for another', function () {
    $seller = Seller::factory()->create();
    $intruder = Seller::factory()->create();

    $this->actingAs($intruder->user)
        ->post(route('seller.policies.store', ['type' => PolicyType::Refund->value]), [
            'body' => 'Everything is final sale and nothing is ever refunded here.',
        ]);

    expect($seller->refresh()->currentPolicy(PolicyType::Refund))->toBeNull();
});
