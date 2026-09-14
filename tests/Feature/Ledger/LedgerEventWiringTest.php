<?php

declare(strict_types=1);

use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Jobs\ReleaseSellerReserves;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Events\DisputeResolved;
use App\Modules\Orders\Events\OrderCompleted;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use Database\Seeders\SettingsSeeder;

/**
 * The seam between Orders and the ledger.
 *
 * Orders fires; the ledger posts. The listeners are queued, so these run them
 * synchronously — what is being asserted is that the right recipe fires for
 * the right event, not that the queue works.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->balances = app(LedgerBalances::class);
});

it('posts the payment when an order is paid', function (): void {
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);

    OrderPaid::dispatch($order->id, 'MFA-1042-1', $order->stockLines());

    expect(JournalEntry::query()->sole()->recipe)->toBe(PostingRecipe::EscrowPayment)
        ->and($this->balances->escrowHeldFor($order))->toBeMoney(100_000);
});

it('posts by the order\'s snapshot, not the seller\'s current mode', function (): void {
    $seller = Seller::factory()->create(['payment_mode' => PaymentMode::Escrow]);
    $order = Order::factory()->for($seller)->directSettlement()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);

    /* The seller was moved back to escrow after the money arrived. */
    OrderPaid::dispatch($order->id, 'MFA-1043-1', $order->stockLines());

    expect(JournalEntry::query()->sole()->recipe)->toBe(PostingRecipe::DirectPayment)
        ->and($this->balances->escrowHeldFor($order))->toBeMoney(0)
        ->and($this->balances->sellerPayable($seller))->toBeMoney(81_300);
});

it('releases the escrow when an order completes', function (): void {
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);

    app(OrderPostingService::class)->recordPayment($order);

    OrderCompleted::dispatch($order, true);

    expect($this->balances->escrowHeldFor($order))->toBeMoney(0)
        ->and($this->balances->sellerPayable($order->seller))->toBeMoney(91_300)
        ->and($this->balances->forAccount(LedgerAccountCode::CommissionRevenue))->toBeMoney(7_500);
});

it('posts nothing extra when a dispute is released to the seller', function (): void {
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);
    app(OrderPostingService::class)->recordPayment($order);

    $dispute = OrderDispute::factory()->for($order)->create();

    DisputeResolved::dispatch($dispute, DisputeResolution::Release, Money::zero());

    /* Only the payment. Completion is what releases, and it has not happened. */
    expect(JournalEntry::query()->count())->toBe(1)
        ->and($this->balances->escrowHeldFor($order))->toBeMoney(100_000);
});

it('refunds a full refund out of escrow', function (): void {
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);
    app(OrderPostingService::class)->recordPayment($order);

    $dispute = OrderDispute::factory()->for($order)->create();

    DisputeResolved::dispatch($dispute, DisputeResolution::FullRefund, Money::ofNgwee(100_000));

    expect($this->balances->escrowHeldFor($order))->toBeMoney(0)
        ->and($this->balances->forAccount(LedgerAccountCode::PlatformCash))->toBeMoney(0)
        ->and($this->balances->sellerPayable($order->seller))->toBeMoney(0);
});

it('refunds a partial refund and leaves the rest to release', function (): void {
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);
    app(OrderPostingService::class)->recordPayment($order);

    $dispute = OrderDispute::factory()->for($order)->create();

    DisputeResolved::dispatch($dispute, DisputeResolution::PartialRefund, Money::ofNgwee(30_000));

    expect($this->balances->escrowHeldFor($order))->toBeMoney(70_000);

    /* The resolution completes the order, which is what releases the rest. */
    OrderCompleted::dispatch($order->fresh(), false);

    expect($this->balances->escrowHeldFor($order))->toBeMoney(0)
        /* Commission on the 70_000 the buyer kept. */
        ->and($this->balances->forAccount(LedgerAccountCode::CommissionRevenue))->toBeMoney(5_250)
        ->and($this->balances->sellerPayable($order->seller))->toBeMoney(63_910);
});

it('refunds one dispute once, however many times the event is replayed', function (): void {
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);
    app(OrderPostingService::class)->recordPayment($order);

    $dispute = OrderDispute::factory()->for($order)->create();

    DisputeResolved::dispatch($dispute, DisputeResolution::PartialRefund, Money::ofNgwee(30_000));
    DisputeResolved::dispatch($dispute, DisputeResolution::PartialRefund, Money::ofNgwee(30_000));

    expect($this->balances->escrowHeldFor($order))->toBeMoney(70_000)
        ->and(JournalEntry::query()->where('recipe', PostingRecipe::EscrowRefund)->count())->toBe(1);
});

it('trims a rolling reserve back to the trailing requirement', function (): void {
    $seller = Seller::factory()->create(['payment_mode' => PaymentMode::Direct]);

    $order = Order::factory()->for($seller)->directSettlement()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);
    app(OrderPostingService::class)->recordPayment($order);

    expect($this->balances->sellerReserve($seller))->toBeMoney(10_000);

    /* Nothing has aged out yet: the sale is inside the trailing window. */
    app(ReleaseSellerReserves::class)->handle(
        app(OrderPostingService::class),
        $this->balances,
    );

    expect($this->balances->sellerReserve($seller))->toBeMoney(10_000);

    /* Thirty-one days later that sale no longer counts towards the requirement. */
    $this->travel(31)->days();

    app(ReleaseSellerReserves::class)->handle(
        app(OrderPostingService::class),
        $this->balances,
    );

    expect($this->balances->sellerReserve($seller))->toBeMoney(0)
        ->and($this->balances->sellerPayable($seller))->toBeMoney(91_300);
});

it('releases a reserve once a day, however often the job runs', function (): void {
    $seller = Seller::factory()->create(['payment_mode' => PaymentMode::Direct]);
    $order = Order::factory()->for($seller)->directSettlement()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
    ]);
    app(OrderPostingService::class)->recordPayment($order);

    $this->travel(31)->days();

    app(ReleaseSellerReserves::class)->handle(app(OrderPostingService::class), $this->balances);
    app(ReleaseSellerReserves::class)->handle(app(OrderPostingService::class), $this->balances);

    expect(JournalEntry::query()->where('recipe', PostingRecipe::ReserveRelease)->count())->toBe(1);
});
