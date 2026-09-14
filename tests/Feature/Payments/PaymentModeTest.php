<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Jobs\RevertRiskySellersToEscrow;
use App\Modules\Payments\Notifications\SellerRevertedToEscrow;
use App\Modules\Payments\Services\PaymentModeService;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Who is trusted to be paid on payment rather than on completion.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->modes = app(PaymentModeService::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::PlatformAdmin->value);
});

it('recommends direct settlement for a seller who meets every criterion', function (): void {
    $eligibility = $this->modes->eligibility(sellerWithHistory(25));

    expect($eligibility->isEligible())->toBeTrue()
        ->and($eligibility->isVerified)->toBeTrue()
        ->and($eligibility->hasEnoughOrders())->toBeTrue()
        ->and($eligibility->hasAcceptableDisputeRate())->toBeTrue()
        ->and($eligibility->isEstablished())->toBeTrue();
});

/**
 * The screen shows WHICH criterion failed, because "verified but only 14
 * orders" and "40 orders but a 6% dispute rate" are different risks.
 */
it('says which criterion a seller falls short on', function (): void {
    $eligibility = $this->modes->eligibility(sellerWithHistory(5));

    expect($eligibility->isEligible())->toBeFalse()
        ->and($eligibility->hasEnoughOrders())->toBeFalse()
        ->and($eligibility->isVerified)->toBeTrue();

    $orders = collect($eligibility->criteria())->firstWhere('key', 'orders');

    expect($orders['met'])->toBeFalse()
        ->and($orders['detail'])->toBe('5 of 20');
});

it('does not recommend an unverified seller however good their numbers', function (): void {
    $seller = sellerWithHistory(50, 0, [
        'verification_status' => VerificationStatus::Submitted,
        'verified_at' => null,
    ]);

    expect($this->modes->eligibility($seller)->isEligible())->toBeFalse();
});

it('records the eligibility as it stood when an administrator overrides it', function (): void {
    $seller = sellerWithHistory(3);

    $this->modes->setMode($seller, PaymentMode::Direct, $this->admin, 'Strategic supplier, agreed with the MD.');

    expect($seller->refresh()->payment_mode)->toBe(PaymentMode::Direct);

    $audit = AuditLog::query()->where('action', 'sellers.payment_mode.changed')->sole();

    expect($audit->reason)->toBe('Strategic supplier, agreed with the MD.')
        ->and($audit->after['was_recommended'])->toBeFalse()
        ->and($audit->after['eligibility']['completed_orders'])->toBe(3);
});

it('reverts a direct seller whose dispute rate crosses the threshold', function (): void {
    Notification::fake();

    $finance = User::factory()->create();
    $finance->assignRole(Role::Finance->value);

    /* 4 disputes in 20 orders is 20%, far above the 2% threshold. */
    $seller = sellerWithHistory(20, 4, ['payment_mode' => PaymentMode::Direct]);

    expect($this->modes->shouldRevert($seller))->toBeTrue();

    app(RevertRiskySellersToEscrow::class)->handle($this->modes);

    expect($seller->refresh()->payment_mode)->toBe(PaymentMode::Escrow);

    $audit = AuditLog::query()->where('action', 'sellers.payment_mode.auto_reverted')->sole();

    expect((float) $audit->after['dispute_rate_percent'])->toBe(20.0)
        ->and($audit->actor_id)->toBeNull();

    Notification::assertSentTo($finance, SellerRevertedToEscrow::class);
});

it('leaves a direct seller alone while their dispute rate is acceptable', function (): void {
    $seller = sellerWithHistory(50, 0, ['payment_mode' => PaymentMode::Direct]);

    app(RevertRiskySellersToEscrow::class)->handle($this->modes);

    expect($seller->refresh()->payment_mode)->toBe(PaymentMode::Direct);
});

/**
 * One dispute out of two orders is a 50% rate and means nothing at all.
 */
it('never reverts a seller with too little history to measure', function (): void {
    $seller = sellerWithHistory(3, 2, ['payment_mode' => PaymentMode::Direct]);

    expect($this->modes->disputeRatePercent($seller))->toBeGreaterThan($this->modes->threshold())
        ->and($this->modes->shouldRevert($seller))->toBeFalse();

    app(RevertRiskySellersToEscrow::class)->handle($this->modes);

    expect($seller->refresh()->payment_mode)->toBe(PaymentMode::Direct);
});

/**
 * Reversion is one-way. Regaining trust is a decision, not a sweep.
 */
it('never grants direct settlement automatically', function (): void {
    $seller = sellerWithHistory(50, 0, ['payment_mode' => PaymentMode::Escrow]);

    app(RevertRiskySellersToEscrow::class)->handle($this->modes);

    expect($seller->refresh()->payment_mode)->toBe(PaymentMode::Escrow);
});

it('does not audit a change that changes nothing', function (): void {
    $seller = sellerWithHistory(25, 0, ['payment_mode' => PaymentMode::Escrow]);

    $this->modes->setMode($seller, PaymentMode::Escrow, $this->admin);

    expect(AuditLog::query()->where('action', 'sellers.payment_mode.changed')->count())->toBe(0);
});

it('measures the dispute rate over the trailing window only', function (): void {
    $seller = Seller::factory()->create(['payment_mode' => PaymentMode::Direct]);

    /* Ten clean orders inside the window. */
    Order::factory()->count(10)->for($seller)->create([
        'status' => OrderStatus::Completed,
        'paid_at' => now()->subDays(5),
    ]);

    /* An old disaster, well outside it. */
    Order::factory()->count(10)->for($seller)->create([
        'status' => OrderStatus::Completed,
        'paid_at' => now()->subDays(400),
        'disputed_at' => now()->subDays(399),
    ]);

    expect($this->modes->disputeRatePercent($seller))->toBe(0.0);
});
