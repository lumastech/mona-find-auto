<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\FakePaymentGateway;
use App\Models\User;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\CollectionService;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * The pages, and who may see them.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    config()->set('lenco.public_key', 'pub_test_visible');
    config()->set('lenco.secret_key', 'sk_test_MUST_NEVER_LEAK');
    config()->set('lenco.environment', 'sandbox');
});

/**
 * THE security assertion for this module: the rendered page carries the
 * public key and no trace of the secret one, anywhere in the payload.
 */
it('never puts the secret key in the props it hands the browser', function (): void {
    $group = payableGroup();

    $response = $this->actingAs($group->buyer)->get(route('payments.show', $group->public_id));

    $response->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('storefront/payments/Pay')
            ->where('lenco.publicKey', 'pub_test_visible')
            ->where('lenco.currency', 'ZMW')
            ->where('lenco.environment', 'sandbox')
    );

    /* The whole rendered response, not just the props we thought to check. */
    expect($response->getContent())->not->toContain('sk_test_MUST_NEVER_LEAK')
        ->and($response->getContent())->not->toContain('secret');
});

it('hands the widget a decimal amount and the sandbox script', function (): void {
    $group = payableGroup(123_456);

    $this->actingAs($group->buyer)
        ->get(route('payments.show', $group->public_id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lenco.amount', '1234.56')
            ->where('lenco.amountNgwee', 123_456)
            ->where('lenco.widgetUrl', 'https://pay.sandbox.lenco.co/js/v1/inline.js')
            ->where('lenco.reference', 'MFA-'.$group->public_id.'-1')
        );
});

it('uses the live widget host outside sandbox', function (): void {
    config()->set('lenco.environment', 'live');

    $group = payableGroup();

    $this->actingAs($group->buyer)
        ->get(route('payments.show', $group->public_id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lenco.widgetUrl', 'https://pay.lenco.co/js/v1/inline.js')
        );
});

it('refuses to show another buyer the pay page', function (): void {
    $group = payableGroup();

    $this->actingAs(User::factory()->create())
        ->get(route('payments.show', $group->public_id))
        ->assertForbidden();
});

it('sends a guest to log in', function (): void {
    $group = payableGroup();

    $this->get(route('payments.show', $group->public_id))->assertRedirect(route('login'));
});

/**
 * The verify endpoint takes no input. A buyer replaying it achieves one
 * thing: a second status check.
 */
it('ignores whatever the browser posts and asks the gateway itself', function (): void {
    $group = payableGroup();
    $attempt = app(CollectionService::class)->begin($group);
    $this->gateway->settleCollection($attempt['reference']);

    $this->actingAs($group->buyer)
        ->postJson(route('payments.verify', $group->public_id), [
            'status' => 'failed',
            'amount' => 1,
            'reference' => 'MFA-SOMEBODY-ELSES-1',
        ])
        ->assertOk()
        ->assertJson(['status' => 'successful', 'reference' => $attempt['reference']]);

    expect($group->refresh()->isPaid())->toBeTrue();
});

it('shows the status page and stops polling once paid', function (): void {
    $group = payableGroup();
    $attempt = app(CollectionService::class)->begin($group);
    $this->gateway->settleCollection($attempt['reference']);
    app(CollectionService::class)->settle($attempt['reference']);

    $this->actingAs($group->buyer)
        ->get(route('payments.status', $group->public_id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('storefront/payments/Status')
            ->where('group.isPaid', true)
            ->where('isPending', false)
            ->where('retryUrl', null)
        );
});

it('offers a retry on the status page after a failure', function (): void {
    $group = payableGroup();
    $attempt = app(CollectionService::class)->begin($group);
    $this->gateway->failCollection($attempt['reference'], 'Declined');
    app(CollectionService::class)->verify($attempt['reference']);

    $this->actingAs($group->buyer)
        ->get(route('payments.status', $group->public_id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payment.failureReason', 'Declined')
            ->where('group.isPaid', false)
            ->where('retryUrl', route('payments.show', $group->public_id))
        );
});

it('sends a paid buyer straight to the status page rather than starting another attempt', function (): void {
    $group = payableGroup();
    $attempt = app(CollectionService::class)->begin($group);
    $this->gateway->settleCollection($attempt['reference']);
    app(CollectionService::class)->settle($attempt['reference']);

    $this->actingAs($group->buyer)
        ->get(route('payments.show', $group->public_id))
        ->assertRedirect(route('payments.status', $group->public_id));

    expect($group->refresh()->payment_attempts)->toBe(1);
});

it('keeps sellers and moderators out of the payout console', function (): void {
    foreach ([Role::Seller, Role::Moderator, Role::Buyer] as $role) {
        $user = User::factory()->withTwoFactor()->create();
        $user->assignRole($role->value);

        $this->actingAs($user)->get(route('admin.payouts.index'))->assertForbidden();
    }
});

it('lets Finance see the payout console', function (): void {
    $finance = User::factory()->withTwoFactor()->create();
    $finance->assignRole(Role::Finance->value);
    PayoutBatch::factory()->create();

    $this->actingAs($finance)
        ->get(route('admin.payouts.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/payouts/Index'));
});

/**
 * Dual control, at the HTTP layer: the preparer is refused by policy before
 * the service is ever reached.
 */
it('refuses the preparer the approve route', function (): void {
    $finance = User::factory()->withTwoFactor()->create();
    $finance->assignRole(Role::Finance->value);

    $batch = PayoutBatch::factory()->create(['prepared_by' => $finance->getKey(), 'line_count' => 1]);

    $this->actingAs($finance)
        ->post(route('admin.payouts.approve', $batch), ['note' => 'Looks fine to me.'])
        ->assertForbidden();
});

it('shows Finance the refund queue and lets them clear a manual refund', function (): void {
    $finance = User::factory()->withTwoFactor()->create();
    $finance->assignRole(Role::Finance->value);

    $refund = Refund::factory()->manual()->create();

    $this->actingAs($finance)
        ->get(route('admin.refunds.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/refunds/Index')
            ->has('refunds', 1)
        );

    $this->actingAs($finance)
        ->post(route('admin.refunds.complete', $refund), ['note' => 'Done in the dashboard.'])
        ->assertRedirect();

    expect($refund->refresh()->status->value)->toBe('completed');
});

it('shows an administrator the direct-settlement recommendation', function (): void {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole(Role::PlatformAdmin->value);

    $seller = sellerWithHistory(25);

    $this->actingAs($admin)
        ->get(route('admin.sellers.payment-mode.edit', $seller))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/sellers/PaymentMode')
            ->where('eligibility.is_eligible', true)
            ->has('eligibility.criteria', 4)
        );
});

it('demands a reason before overriding the recommendation', function (): void {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole(Role::PlatformAdmin->value);

    $seller = sellerWithHistory(2);

    $this->actingAs($admin)
        ->put(route('admin.sellers.payment-mode.update', $seller), ['payment_mode' => 'direct'])
        ->assertSessionHasErrors('reason');

    expect($seller->refresh()->payment_mode->value)->toBe('escrow');

    $this->actingAs($admin)
        ->put(route('admin.sellers.payment-mode.update', $seller), [
            'payment_mode' => 'direct',
            'reason' => 'Strategic supplier, approved by the MD.',
        ])
        ->assertSessionHasNoErrors();

    expect($seller->refresh()->payment_mode->value)->toBe('direct');
});

it('shows a seller their earnings but never a way to move money', function (): void {
    $seller = Seller::factory()->create();
    $owner = $seller->user->refresh();

    $this->actingAs($owner)
        ->get(route('seller.earnings.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('seller/earnings/Index')
            ->has('summary.payableNgwee')
            ->has('summary.reserveNgwee')
            ->where('summary.paymentMode', 'escrow')
        );
});
