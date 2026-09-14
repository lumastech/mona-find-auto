<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Integrations\Payments\FakePaymentGateway;
use App\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentSource;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Exceptions\PaymentUnavailable;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CollectionService;
use App\Support\Database\ImmutableRecordException;
use App\Support\Money\Money;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Event;

/**
 * Taking the buyer's money.
 *
 * The gateway is the in-memory fake throughout, driven the way the real one
 * behaves: attempts start pending and are settled by an explicit call, which
 * is the fake standing in for the webhook Lenco would send.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    $this->collections = app(CollectionService::class);
});

it('starts an attempt and records it as pending before the buyer touches anything', function (): void {
    $group = payableGroup();

    $attempt = $this->collections->begin($group);

    expect($attempt['reference'])->toBe('MFA-'.$group->public_id.'-1')
        ->and($attempt['attempt'])->toBe(1)
        ->and($attempt['amount'])->toBe('1000.00')
        ->and($attempt['amount_ngwee'])->toBe(100_000);

    /* The pending row is what gives the stuck-payment poller something to find. */
    $payment = Payment::currentFor($attempt['reference']);

    expect($payment?->status)->toBe(PaymentStatus::Pending)
        ->and($payment?->source)->toBe(PaymentSource::Initiate);
});

it('gives each retry a new reference on the same group', function (): void {
    $group = payableGroup();

    $first = $this->collections->begin($group);
    $second = $this->collections->begin($group->refresh());

    expect($first['reference'])->toBe('MFA-'.$group->public_id.'-1')
        ->and($second['reference'])->toBe('MFA-'.$group->public_id.'-2')
        ->and($group->refresh()->payment_attempts)->toBe(2);
});

it('settles the orders when the gateway confirms the money', function (): void {
    Event::fake([PaymentSucceeded::class]);

    $group = payableGroup();
    $attempt = $this->collections->begin($group);

    $this->gateway->settleCollection($attempt['reference']);

    $payment = $this->collections->settle($attempt['reference']);

    expect($payment->status)->toBe(PaymentStatus::Successful)
        ->and($group->refresh()->isPaid())->toBeTrue()
        ->and($group->orders()->first()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(PaymentSucceeded::class);
});

/**
 * The central guarantee of the whole module. The browser's verify call, the
 * webhook and the poller all race; only one may settle.
 */
it('settles once however many times confirmation arrives', function (): void {
    Event::fake([PaymentSucceeded::class]);

    $group = payableGroup();
    $attempt = $this->collections->begin($group);
    $this->gateway->settleCollection($attempt['reference']);

    $this->collections->settle($attempt['reference'], PaymentSource::Verify);
    $this->collections->settle($attempt['reference'], PaymentSource::Webhook);
    $this->collections->settle($attempt['reference'], PaymentSource::Poll);

    expect(Payment::query()->where('reference', $attempt['reference'])
        ->where('status', PaymentStatus::Successful)->count())->toBe(1);

    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('records a failure and leaves the orders alone so the buyer can retry', function (): void {
    Event::fake([PaymentFailed::class]);

    $group = payableGroup();
    $attempt = $this->collections->begin($group);

    $this->gateway->failCollection($attempt['reference'], 'Insufficient funds');

    $payment = $this->collections->verify($attempt['reference']);

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('Insufficient funds')
        /* Not cancelled: a declined card is a buyer about to try again. */
        ->and($group->orders()->first()->status)->not->toBe(OrderStatus::Cancelled)
        ->and($group->refresh()->isPaid())->toBeFalse();

    Event::assertDispatched(PaymentFailed::class);
});

/**
 * Webhooks arrive out of order. A stale failure must never unpay an order.
 */
it('ignores a late failure on a payment already confirmed', function (): void {
    $group = payableGroup();
    $attempt = $this->collections->begin($group);

    $this->gateway->settleCollection($attempt['reference']);
    $this->collections->settle($attempt['reference']);

    $this->gateway->failCollection($attempt['reference'], 'Late failure');
    $this->collections->fail($attempt['reference'], PaymentSource::Webhook);

    expect($group->refresh()->isPaid())->toBeTrue()
        ->and(Payment::isSettled($attempt['reference']))->toBeTrue()
        ->and(Payment::query()->where('reference', $attempt['reference'])
            ->where('status', PaymentStatus::Failed)->exists())->toBeFalse();
});

it('keeps a pending attempt pending without settling anything', function (): void {
    $group = payableGroup();
    $attempt = $this->collections->begin($group);

    $payment = $this->collections->verify($attempt['reference']);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($group->refresh()->isPaid())->toBeFalse();
});

it('pushes a mobile-money prompt and waits for the handset', function (): void {
    $group = payableGroup(250_00);

    $payment = $this->collections->collectMobileMoney($group, '260971234567', 'mtn');

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($group->refresh()->isPaid())->toBeFalse();

    expect($this->gateway->calls('collectMobileMoney'))->toHaveCount(1);
});

it('refuses to start a second payment on a group already paid', function (): void {
    $group = payableGroup();
    $attempt = $this->collections->begin($group);
    $this->gateway->settleCollection($attempt['reference']);
    $this->collections->settle($attempt['reference']);

    $this->collections->begin($group->refresh());
})->throws(PaymentUnavailable::class, 'already been paid');

it('records what the gateway says was collected, not what the caller claims', function (): void {
    $group = payableGroup(100_000);
    $attempt = $this->collections->begin($group);

    /* The gateway is the authority on the amount. */
    $this->gateway->settleCollection($attempt['reference'], Money::ofNgwee(1_500));

    $payment = $this->collections->settle($attempt['reference']);

    expect($payment->amount_ngwee->ngwee)->toBe(100_000)
        ->and($payment->fee_ngwee?->ngwee)->toBe(1_500);
});

/**
 * The append-only guarantee, at the model layer. The database triggers
 * enforce the same rule for anything that bypasses Eloquent.
 */
it('refuses to let a payment observation be edited or deleted', function (): void {
    $payment = Payment::factory()->successful()->create();

    expect(fn () => $payment->forceFill(['amount_ngwee' => 1])->save())
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $payment->delete())->toThrow(ImmutableRecordException::class);
});

it('finds attempts that have been pending too long and no others', function (): void {
    $stuck = Payment::factory()->stuck(30)->create(['reference' => 'MFA-STUCK-1']);
    Payment::factory()->create(['reference' => 'MFA-FRESH-1', 'observed_at' => now()]);

    /* Pending then settled: resolved, so not stuck. */
    Payment::factory()->stuck(30)->create(['reference' => 'MFA-DONE-1']);
    Payment::factory()->successful()->create(['reference' => 'MFA-DONE-1', 'observed_at' => now()]);

    $found = Payment::query()->stuckPending(15)->pluck('reference')->all();

    expect($found)->toBe([$stuck->reference]);
});

it('only lets the buyer who owns a group pay for it', function (): void {
    $group = payableGroup();
    $stranger = User::factory()->create();

    expect($this->collections->canBePaidBy($group, $stranger))->toBeFalse()
        ->and($this->collections->canBePaidBy($group, $group->buyer))->toBeTrue();
});
