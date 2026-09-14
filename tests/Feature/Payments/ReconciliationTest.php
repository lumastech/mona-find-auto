<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\SettlementRecord;
use App\Integrations\Payments\FakePaymentGateway;
use App\Models\User;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\ReconciliationExceptionType;
use App\Modules\Payments\Enums\ReconciliationStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\ReconciliationRun;
use App\Modules\Payments\Services\ReconciliationService;
use App\Support\Money\Money;
use App\Support\Roles\Role;
use Carbon\CarbonInterface;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;

/**
 * Does what Lenco thinks happened match what our books say happened?
 *
 * Each test seeds a specific disagreement and asserts it is found — and, just
 * as importantly, that a day with no disagreement comes back clean rather
 * than empty.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    $this->reconciliation = app(ReconciliationService::class);
    $this->today = Carbon::parse('2026-09-11');

    Carbon::setTestNow($this->today->copy()->endOfDay());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Tell the fake gateway it collected this, successfully.
 */
function gatewayCollected(FakePaymentGateway $gateway, string $reference, int $ngwee): void
{
    $gateway->collectionsSucceedImmediately();
    $gateway->initiateCollection(Money::ofNgwee($ngwee), $reference, []);
}

/**
 * Record the platform's own side: a Payment row and the ledger posting.
 */
function platformRecorded(string $reference, int $ngwee): Payment
{
    return platformRecordedAt($reference, $ngwee, now());
}

/**
 * The same, with the observation stamped at a chosen moment.
 */
function platformRecordedAt(string $reference, int $ngwee, DateTimeInterface $observedAt): Payment
{
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => $ngwee, 'delivery_fee_ngwee' => 0, 'total_ngwee' => $ngwee,
    ]);

    app(OrderPostingService::class)->recordPayment($order, $reference);

    return Payment::factory()->successful()->create([
        'reference' => $reference,
        'order_group_id' => $order->order_group_id,
        'amount_ngwee' => $ngwee,
        'observed_at' => $observedAt,
    ]);
}

it('records a clean run when both sides agree', function (): void {
    gatewayCollected($this->gateway, 'MFA-AGREE-1', 100_000);
    platformRecorded('MFA-AGREE-1', 100_000);

    $run = $this->reconciliation->run($this->today);

    expect($run->status)->toBe(ReconciliationStatus::Clean)
        ->and($run->exception_count)->toBe(0)
        ->and($run->collections_checked)->toBe(1)
        ->and($run->gateway_total_ngwee->ngwee)->toBe(100_000)
        ->and($run->ledger_total_ngwee->ngwee)->toBe(100_000)
        ->and($run->variance_ngwee->ngwee)->toBe(0);
});

/**
 * The worst failure on the platform: a buyer has paid and we do not know.
 */
it('flags a collection the platform has no payment record for', function (): void {
    gatewayCollected($this->gateway, 'MFA-LOST-1', 75_000);

    $run = $this->reconciliation->run($this->today);

    $missing = $run->exceptions()->where('type', ReconciliationExceptionType::Missing)->sole();

    expect($run->status)->toBe(ReconciliationStatus::Exceptions)
        ->and($missing->severity)->toBe('critical')
        ->and($missing->reference)->toBe('MFA-LOST-1')
        ->and($missing->gateway_amount_ngwee?->ngwee)->toBe(75_000);

    /*
     * A payment we never recorded is also money the ledger never saw, so the
     * day's totals disagree too. Both exceptions are correct and describe the
     * same underlying loss from two directions.
     */
    expect($run->exceptions()->where('type', ReconciliationExceptionType::LedgerVariance)->count())->toBe(1)
        ->and($run->variance_ngwee->ngwee)->toBe(75_000);
});

it('flags a payment the two sides disagree about the amount of', function (): void {
    gatewayCollected($this->gateway, 'MFA-MISMATCH-1', 100_000);
    platformRecorded('MFA-MISMATCH-1', 90_000);

    $run = $this->reconciliation->run($this->today);

    $mismatch = $run->exceptions()
        ->where('type', ReconciliationExceptionType::AmountMismatch)
        ->sole();

    expect($mismatch->gateway_amount_ngwee?->ngwee)->toBe(100_000)
        ->and($mismatch->ledger_amount_ngwee?->ngwee)->toBe(90_000)
        ->and($mismatch->variance_ngwee?->ngwee)->toBe(10_000);
});

it('flags a successful payment the gateway has never heard of', function (): void {
    platformRecorded('MFA-ORPHAN-1', 50_000);

    $run = $this->reconciliation->run($this->today);

    $orphan = $run->exceptions()->where('type', ReconciliationExceptionType::Orphan)->sole();

    expect($orphan->reference)->toBe('MFA-ORPHAN-1')
        ->and($orphan->severity)->toBe('critical');
});

it('flags a collection Lenco has not settled long after taking it', function (): void {
    gatewayCollected($this->gateway, 'MFA-SLOW-1', 100_000);

    /*
     * Taken a week ago, still not in our bank. The row is written old rather
     * than updated afterwards — payments are append-only, and the database
     * trigger refuses the update even from a test.
     */
    platformRecordedAt('MFA-SLOW-1', 100_000, now()->subDays(7));

    $this->gateway->stubSettlement(new SettlementRecord(
        id: 'stl-1',
        amountSettled: Money::zero(),
        status: 'pending',
        collectionReference: 'MFA-SLOW-1',
        collectionAmount: Money::ofNgwee(100_000),
    ));

    $run = $this->reconciliation->run($this->today);

    expect($run->exceptions()->where('type', ReconciliationExceptionType::Unsettled)->count())->toBe(1);
});

it('does not flag a settlement that is merely waiting for next-day', function (): void {
    gatewayCollected($this->gateway, 'MFA-TODAY-1', 100_000);
    platformRecorded('MFA-TODAY-1', 100_000);

    $this->gateway->stubSettlement(new SettlementRecord(
        id: 'stl-2',
        amountSettled: Money::zero(),
        status: 'pending',
        collectionReference: 'MFA-TODAY-1',
        collectionAmount: Money::ofNgwee(100_000),
    ));

    $run = $this->reconciliation->run($this->today);

    expect($run->exceptions()->where('type', ReconciliationExceptionType::Unsettled)->count())->toBe(0);
});

it('replaces a day rather than double-counting it when re-run', function (): void {
    gatewayCollected($this->gateway, 'MFA-LOST-1', 75_000);

    $this->reconciliation->run($this->today);
    $second = $this->reconciliation->run($this->today);

    expect(ReconciliationRun::count())->toBe(1)
        /* The second run replaced the first's exceptions rather than adding to them. */
        ->and($second->exceptions()->count())->toBe($second->exception_count)
        ->and($second->exceptions()->where('type', ReconciliationExceptionType::Missing)->count())->toBe(1);
});

/**
 * Resolution is an annotation. An exception that can be deleted is an
 * exception nobody has to explain.
 */
it('resolves an exception by annotating it, never by removing it', function (): void {
    gatewayCollected($this->gateway, 'MFA-LOST-1', 75_000);
    $run = $this->reconciliation->run($this->today);
    $exception = $run->exceptions()->where('type', ReconciliationExceptionType::Missing)->sole();

    $finance = User::factory()->create();
    $finance->assignRole(Role::Finance->value);

    $resolved = $this->reconciliation->resolve($exception, $finance, 'Manually re-verified; order settled.');

    expect($resolved->isResolved())->toBeTrue()
        ->and($resolved->resolution_note)->toBe('Manually re-verified; order settled.')
        ->and($resolved->resolved_by)->toBe($finance->getKey())
        /* Still there — resolving annotates, it does not delete. */
        ->and($run->exceptions()->whereKey($exception->getKey())->count())->toBe(1)
        ->and($run->exceptions()->outstanding()->whereKey($exception->getKey())->count())->toBe(0);
});

it('ignores collections the gateway did not complete', function (): void {
    $this->gateway->initiateCollection(Money::ofNgwee(40_000), 'MFA-PENDING-1', []);

    $run = $this->reconciliation->run($this->today);

    expect($run->status)->toBe(ReconciliationStatus::Clean)
        ->and($run->gateway_total_ngwee->ngwee)->toBe(0);
});

it('reports a failed run rather than silently recording a clean one', function (): void {
    $this->app->instance(PaymentGateway::class, new class extends FakePaymentGateway
    {
        public function collectionsBetween(CarbonInterface $from, CarbonInterface $to): array
        {
            throw new RuntimeException('Lenco is down.');
        }
    });

    /* The service is a singleton and already holds the old gateway. */
    $this->app->forgetInstance(ReconciliationService::class);
    $reconciliation = app(ReconciliationService::class);

    expect(fn () => $reconciliation->run($this->today))->toThrow(RuntimeException::class, 'Lenco is down.');

    $run = ReconciliationRun::sole();

    expect($run->status)->toBe(ReconciliationStatus::Failed)
        ->and($run->failure_reason)->toBe('Lenco is down.');
});
