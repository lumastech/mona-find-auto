<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\FakePaymentGateway;
use App\Models\User;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\RefundService;
use App\Support\Money\Money;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;

/**
 * Money going back to a buyer.
 *
 * The division of labour under test: Ledger decides what a refund does to the
 * accounts, Payments moves the cash, and for a dispute they must not both
 * post — which is the single easiest thing to get wrong here.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    $this->refunds = app(RefundService::class);
    $this->balances = app(LedgerBalances::class);
    $this->posting = app(OrderPostingService::class);

    $this->finance = User::factory()->create();
    $this->finance->assignRole(Role::Finance->value);
});

/**
 * A paid order with a mobile-money payment behind it, posted to the ledger.
 */
function refundableOrder(int $total = 100_000, bool $byCard = false): Order
{
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => $total,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => $total,
    ]);

    $factory = Payment::factory()->successful();

    if ($byCard) {
        $factory = $factory->card()->successful()->state(['raw' => [
            'type' => 'card', 'bearer' => 'merchant', 'cardDetails' => ['last4' => '4242'],
        ]]);
    }

    $factory->create([
        'order_group_id' => $order->order_group_id,
        'user_id' => $order->user_id,
        'reference' => 'MFA-'.$order->group->public_id.'-1',
        'amount_ngwee' => $total,
    ]);

    app(OrderPostingService::class)->recordPayment($order);

    return $order->refresh();
}

it('sends a mobile-money refund back to the wallet that paid', function (): void {
    $order = refundableOrder();

    $refund = $this->refunds->createForOrder($order, Money::ofNgwee(40_000), RefundReason::AutoCancel);

    expect($refund?->method)->toBe(RefundMethod::MobileMoney)
        ->and($refund?->destination_phone)->toBe('260971234567')
        ->and($refund?->destination_network)->toBe('mtn')
        /* Sent by the queued job, which the sync queue runs inline. */
        ->and($refund->refresh()->status)->toBe(RefundStatus::Sent);
});

/**
 * Lenco reverses cards out of band, so there is no API call that could work.
 * Pretending otherwise costs a buyer a fortnight of waiting.
 */
it('queues a card refund for Finance instead of trying to transfer it', function (): void {
    $order = refundableOrder(byCard: true);

    $refund = $this->refunds->createForOrder($order, Money::ofNgwee(40_000), RefundReason::Dispute);

    expect($refund?->method)->toBe(RefundMethod::CardManual)
        ->and($refund?->status)->toBe(RefundStatus::Manual)
        ->and($refund?->method->needsManualProcessing())->toBeTrue();

    /* Nothing was sent. */
    expect($this->gateway->calls('initiateTransfer'))->toHaveCount(0);
});

it('queues a refund for Finance when the original payment left no usable destination', function (): void {
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 100_000, 'delivery_fee_ngwee' => 0, 'total_ngwee' => 100_000,
    ]);
    $this->posting->recordPayment($order);

    /* No Payment row at all — nothing to refund back to. */
    $refund = $this->refunds->createForOrder($order, Money::ofNgwee(10_000), RefundReason::Admin);

    expect($refund?->status)->toBe(RefundStatus::Manual);
});

it('posts the ledger itself for a cancellation refund', function (): void {
    $order = refundableOrder(100_000);

    $refund = $this->refunds->createForOrder($order, Money::ofNgwee(40_000), RefundReason::AutoCancel);

    expect($refund?->journal_entry_id)->not->toBeNull()
        ->and($this->balances->escrowHeldFor($order->refresh())->ngwee)->toBe(60_000);
});

/**
 * The split that matters. Ledger's own listener posts a dispute refund, so
 * this one must not — or the books are refunded twice for one decision.
 */
it('does not post the ledger twice for a dispute refund', function (): void {
    $order = refundableOrder(100_000);
    $dispute = OrderDispute::factory()->for($order)->create();

    DisputeResolved::dispatch($dispute, DisputeResolution::PartialRefund, Money::ofNgwee(30_000));

    $refund = Refund::query()->where('order_id', $order->getKey())->sole();

    expect($refund->reason_code)->toBe(RefundReason::Dispute)
        /* Escrow is down by exactly one refund, not two. */
        ->and($this->balances->escrowHeldFor($order->refresh())->ngwee)->toBe(70_000);
});

it('numbers repeat refunds on the same order so references never collide', function (): void {
    $order = refundableOrder(100_000);

    $first = $this->refunds->createForOrder($order, Money::ofNgwee(10_000), RefundReason::Admin);
    $second = $this->refunds->createForOrder($order, Money::ofNgwee(10_000), RefundReason::Admin);

    expect($first?->reference)->toBe('RF-'.$order->number.'-1')
        ->and($second?->reference)->toBe('RF-'.$order->number.'-2')
        ->and($first?->reference)->not->toBe($second?->reference);
});

it('ignores a refund of nothing', function (): void {
    $order = refundableOrder();

    expect($this->refunds->createForOrder($order, Money::zero(), RefundReason::Admin))->toBeNull()
        ->and(Refund::count())->toBe(0);
});

it('completes a refund when the transfer confirms', function (): void {
    $order = refundableOrder();
    $refund = $this->refunds->createForOrder($order, Money::ofNgwee(40_000), RefundReason::AutoCancel);

    $this->gateway->settleTransfer($refund->reference);
    $this->refunds->syncFromGateway($refund->reference);

    expect($refund->refresh()->status)->toBe(RefundStatus::Completed)
        ->and($refund->completed_at)->not->toBeNull();
});

/**
 * A bounced refund still leaves the platform owing the buyer. Only the
 * delivery failed, so nothing in the ledger is reversed.
 */
it('keeps the refund owed when the transfer bounces', function (): void {
    $order = refundableOrder(100_000);
    $refund = $this->refunds->createForOrder($order, Money::ofNgwee(40_000), RefundReason::AutoCancel);
    $escrowAfterRefund = $this->balances->escrowHeldFor($order->refresh());

    $this->gateway->failTransfer($refund->reference, 'Wallet not reachable');
    $this->refunds->syncFromGateway($refund->reference);

    expect($refund->refresh()->status)->toBe(RefundStatus::Failed)
        ->and($refund->failure_reason)->toBe('Wallet not reachable')
        /* Unchanged: the platform still owes this money. */
        ->and($this->balances->escrowHeldFor($order->refresh())->ngwee)->toBe($escrowAfterRefund->ngwee);

    expect(Refund::query()->needingAttention()->count())->toBe(1);
});

it('lets Finance clear a manual refund by hand', function (): void {
    $refund = Refund::factory()->manual()->create();

    $cleared = $this->refunds->completeManually($refund, $this->finance, 'Reversed in the Lenco dashboard.');

    expect($cleared->status)->toBe(RefundStatus::Completed)
        ->and($cleared->processed_by)->toBe($this->finance->getKey())
        ->and($cleared->notes)->toBe('Reversed in the Lenco dashboard.');
});

it('does not resend a refund that already went', function (): void {
    $order = refundableOrder();
    $refund = $this->refunds->createForOrder($order, Money::ofNgwee(40_000), RefundReason::AutoCancel);

    $sentCalls = count($this->gateway->calls('initiateTransfer'));

    $this->refunds->send($refund->refresh());

    expect($this->gateway->calls('initiateTransfer'))->toHaveCount($sentCalls);
});

it('never refunds more than the order was worth', function (): void {
    $order = refundableOrder(100_000);

    $this->refunds->createForOrder($order, Money::ofNgwee(80_000), RefundReason::Admin);
    $this->refunds->createForOrder($order->refresh(), Money::ofNgwee(80_000), RefundReason::Admin);

    /* The ledger caps the second refund at what is left. */
    expect($this->balances->escrowHeldFor($order->refresh())->ngwee)->toBe(0)
        ->and($this->balances->forAccount(LedgerAccountCode::EscrowHeld)->ngwee)->toBe(0);
});
