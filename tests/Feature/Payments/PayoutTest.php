<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\FakePaymentGateway;
use App\Models\User;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PayoutBatchStatus;
use App\Modules\Payments\Enums\PayoutLineStatus;
use App\Modules\Payments\Exceptions\PayoutNotAllowed;
use App\Modules\Payments\Jobs\ExecutePayoutLine;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Services\PayoutService;
use App\Modules\Sellers\Database\Factories\PayoutAccountFactory;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * Paying sellers.
 *
 * The ledger is posted for real throughout rather than mocked, because the
 * questions worth asking here are all about what a payout does to a seller's
 * payable — and a mocked ledger would answer them by construction.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    $this->payouts = app(PayoutService::class);
    $this->balances = app(LedgerBalances::class);
    $this->posting = app(OrderPostingService::class);

    $this->finance = User::factory()->create();
    $this->finance->assignRole(Role::Finance->value);

    $this->approver = User::factory()->create();
    $this->approver->assignRole(Role::Finance->value);
});

/**
 * A seller who is genuinely owed money, with somewhere to send it.
 *
 * The payable is created by completing a real escrow order rather than by
 * writing a balance, so the figure under test is the one the ledger actually
 * produces from a sale.
 */
function sellerOwedMoney(int $orderTotal = 100_000, string $resolvedName = 'MULENGA BANDA LTD'): Seller
{
    $seller = Seller::factory()->create();

    $account = PayoutAccountFactory::new()->mobileMoney()->create([
        'seller_id' => $seller->getKey(),
        'resolved_name' => $resolvedName,
        'is_default' => true,
    ]);

    /*
     * The gateway has to agree about whose account this is, or the re-resolve
     * check at payout time blocks the line — which is exactly what it is for,
     * and what the blocking tests below override this to demonstrate.
     */
    test()->gateway->stubAccountName($account->mobile_number, $resolvedName);

    $order = Order::factory()->for($seller)->paid()->create([
        'items_total_ngwee' => $orderTotal,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => $orderTotal,
    ]);

    app(OrderPostingService::class)->recordPayment($order);
    app(OrderPostingService::class)->releaseEscrow($order);

    return $seller->refresh();
}

it('builds a batch from every seller currently owed money', function (): void {
    $seller = sellerOwedMoney();
    $payable = $this->balances->sellerPayable($seller);

    $batch = $this->payouts->build($this->finance);

    expect($batch->status)->toBe(PayoutBatchStatus::AwaitingApproval)
        ->and($batch->line_count)->toBe(1)
        ->and($batch->total_ngwee->ngwee)->toBe($payable->ngwee)
        ->and($batch->lines()->sole()->seller_id)->toBe($seller->getKey());
});

it('skips sellers with no payout account rather than adding a line that must fail', function (): void {
    $seller = Seller::factory()->create();
    $order = Order::factory()->for($seller)->paid()->create([
        'items_total_ngwee' => 100_000, 'delivery_fee_ngwee' => 0, 'total_ngwee' => 100_000,
    ]);
    $this->posting->recordPayment($order);
    $this->posting->releaseEscrow($order);

    $batch = $this->payouts->build($this->finance);

    expect($batch->line_count)->toBe(0)
        /* The money stays owed; they are picked up once they add an account. */
        ->and($this->balances->sellerPayable($seller)->isPositive())->toBeTrue();
});

it('rolls small balances forward rather than paying fees to move them', function (): void {
    settings()->set('payouts.minimum_ngwee', 50_000);

    sellerOwedMoney(2_000);

    expect($this->payouts->build($this->finance)->line_count)->toBe(0);
});

/**
 * Dual control. The single most important rule in this module.
 */
it('refuses to let the person who built a batch approve it', function (): void {
    sellerOwedMoney();
    $batch = $this->payouts->build($this->finance);

    $this->payouts->approve($batch, $this->finance);
})->throws(PayoutNotAllowed::class, 'someone other than the person who prepared it');

it('lets a second finance user approve it', function (): void {
    sellerOwedMoney();
    $batch = $this->payouts->build($this->finance);

    $approved = $this->payouts->approve($batch, $this->approver, 'Checked against the ledger.');

    expect($approved->status)->toBe(PayoutBatchStatus::Approved)
        ->and($approved->approved_by)->toBe($this->approver->getKey())
        ->and($approved->approval_note)->toBe('Checked against the ledger.');
});

it('refuses to approve an empty batch', function (): void {
    $batch = $this->payouts->build($this->finance);

    $this->payouts->approve($batch, $this->approver);
})->throws(PayoutNotAllowed::class, 'nothing to pay out');

it('refuses to execute a batch nobody approved', function (): void {
    sellerOwedMoney();
    $batch = $this->payouts->build($this->finance);

    $this->payouts->execute($batch);
})->throws(PayoutNotAllowed::class, 'must be approved first');

/**
 * The happy path, end to end: approved, sent, confirmed, posted.
 */
it('pays a seller and clears their payable when the transfer confirms', function (): void {
    $seller = sellerOwedMoney();
    $payable = $this->balances->sellerPayable($seller);

    $batch = $this->payouts->build($this->finance);
    $this->payouts->approve($batch, $this->approver);
    $this->payouts->execute($batch->refresh());

    /* execute() queues a job per line, and the sync queue sends them inline. */
    $line = $batch->lines()->sole();

    expect($line->status)->toBe(PayoutLineStatus::Sent);

    /* The gateway confirms, as a transfer webhook would. */
    $this->gateway->settleTransfer($line->reference, Money::ofNgwee(850));
    $this->payouts->syncLineFromGateway($line->reference);

    $line->refresh();

    expect($line->status)->toBe(PayoutLineStatus::Paid)
        ->and($line->fee_ngwee?->ngwee)->toBe(850)
        ->and($line->journal_entry_id)->not->toBeNull()
        ->and($this->balances->sellerPayable($seller->refresh())->ngwee)->toBe(0)
        ->and(JournalEntry::find($line->journal_entry_id)->recipe)->toBe(PostingRecipe::Payout)
        ->and($batch->refresh()->status)->toBe(PayoutBatchStatus::Completed)
        ->and($batch->paid_ngwee->ngwee)->toBe($payable->ngwee);
});

/**
 * The gateway's charge is the platform's cost, not a deduction from the
 * seller — the seller is owed what they are owed.
 */
it('charges the gateway fee to the platform, not to the seller', function (): void {
    $seller = sellerOwedMoney();
    $payable = $this->balances->sellerPayable($seller);

    $batch = $this->payouts->build($this->finance);
    $this->payouts->approve($batch, $this->approver);
    $this->payouts->execute($batch->refresh());

    $line = $batch->lines()->sole();
    $this->gateway->settleTransfer($line->reference, Money::ofNgwee(850));
    $this->payouts->syncLineFromGateway($line->reference);

    expect($line->refresh()->amount_ngwee->ngwee)->toBe($payable->ngwee)
        ->and($this->balances->forAccount(LedgerAccountCode::LencoFeesExpense)->ngwee)->toBe(850);
});

/**
 * A failed line must leave the seller's payable intact so the next run picks
 * them up. Nothing is reversed, because nothing was posted — the ledger entry
 * is written on confirmation, never on send.
 */
it('leaves the payable in place when a line fails, and pays the others', function (): void {
    $paidSeller = sellerOwedMoney(100_000);
    $failedSeller = sellerOwedMoney(80_000);
    $owedBefore = $this->balances->sellerPayable($failedSeller);

    $batch = $this->payouts->build($this->finance);
    $this->payouts->approve($batch, $this->approver);
    $this->payouts->execute($batch->refresh());

    expect($batch->refresh()->line_count)->toBe(2);

    $good = $batch->lines()->where('seller_id', $paidSeller->getKey())->sole();
    $bad = $batch->lines()->where('seller_id', $failedSeller->getKey())->sole();

    $this->gateway->settleTransfer($good->reference);
    $this->gateway->failTransfer($bad->reference, 'Account closed');

    $this->payouts->syncLineFromGateway($good->reference);
    $this->payouts->syncLineFromGateway($bad->reference);

    expect($good->refresh()->status)->toBe(PayoutLineStatus::Paid)
        ->and($bad->refresh()->status)->toBe(PayoutLineStatus::Failed)
        ->and($bad->failure_reason)->toBe('Account closed')
        /* Still owed, untouched, ready for the next run. */
        ->and($this->balances->sellerPayable($failedSeller->refresh())->ngwee)->toBe($owedBefore->ngwee)
        ->and($this->balances->sellerPayable($paidSeller->refresh())->ngwee)->toBe(0);

    $batch->refresh();

    expect($batch->status)->toBe(PayoutBatchStatus::Completed)
        ->and($batch->failed_ngwee->ngwee)->toBe($owedBefore->ngwee)
        ->and($batch->linesNeedingAttention())->toHaveCount(1);
});

/**
 * An account that has quietly changed hands is the cheapest thing to catch
 * here and close to impossible to unpick afterwards.
 */
it('blocks a line when the account has been renamed at the gateway', function (): void {
    $seller = sellerOwedMoney(100_000, 'MULENGA BANDA LTD');
    $account = $seller->payoutAccounts()->sole();
    $owedBefore = $this->balances->sellerPayable($seller);

    /* The gateway now reports a different person behind that number. */
    $this->gateway->stubAccountName($account->mobile_number, 'SOMEBODY ELSE ENTIRELY');

    $batch = $this->payouts->build($this->finance);
    $this->payouts->approve($batch, $this->approver);
    $this->payouts->execute($batch->refresh());

    $line = $batch->lines()->sole();

    expect($line->status)->toBe(PayoutLineStatus::Blocked)
        ->and($line->block_reason)->toContain('SOMEBODY ELSE ENTIRELY')
        ->and($this->balances->sellerPayable($seller->refresh())->ngwee)->toBe($owedBefore->ngwee);
});

/**
 * Banks punctuate and capitalise inconsistently. Blocking on that would help
 * nobody; blocking on a different person is the point.
 */
it('accepts a name that differs only in case and punctuation', function (): void {
    $seller = sellerOwedMoney(100_000, 'MULENGA BANDA LTD');
    $account = $seller->payoutAccounts()->sole();

    $this->gateway->stubAccountName($account->mobile_number, 'Mulenga  Banda Ltd.');

    $batch = $this->payouts->build($this->finance);
    $this->payouts->approve($batch, $this->approver);
    $this->payouts->execute($batch->refresh());

    expect($batch->lines()->sole()->status)->toBe(PayoutLineStatus::Sent);
});

it('blocks a line whose account the gateway no longer recognises', function (): void {
    $seller = sellerOwedMoney();

    $batch = $this->payouts->build($this->finance);
    $this->payouts->approve($batch, $this->approver);

    /* The account stops resolving between approval and execution. */
    $this->gateway->resolutionFails();

    $this->payouts->execute($batch->refresh());

    expect($batch->lines()->sole()->status)->toBe(PayoutLineStatus::Blocked)
        ->and($this->balances->sellerPayable($seller->refresh())->isPositive())->toBeTrue();
});

it('posts one ledger entry however many times a transfer is confirmed', function (): void {
    $seller = sellerOwedMoney();

    $batch = $this->payouts->build($this->finance);
    $this->payouts->approve($batch, $this->approver);
    $this->payouts->execute($batch->refresh());

    $line = $batch->lines()->sole();
    $this->gateway->settleTransfer($line->reference);

    $this->payouts->syncLineFromGateway($line->reference);
    $this->payouts->syncLineFromGateway($line->reference);
    $this->payouts->settleLine($line->refresh());

    expect(JournalEntry::query()->where('recipe', PostingRecipe::Payout)->count())->toBe(1);
});

it('cancels a batch that has not started and refuses one that has', function (): void {
    sellerOwedMoney();
    $batch = $this->payouts->build($this->finance);

    $cancelled = $this->payouts->cancel($batch, $this->approver, 'Built by mistake.');

    expect($cancelled->status)->toBe(PayoutBatchStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Built by mistake.');

    $started = PayoutBatch::factory()->processing()->create();

    expect(fn () => $this->payouts->cancel($started, $this->approver, 'Too late.'))
        ->toThrow(PayoutNotAllowed::class);
});

it('sends one line per job and never two workers the same line', function (): void {
    Queue::fake();

    sellerOwedMoney(100_000);
    sellerOwedMoney(80_000);

    $batch = $this->payouts->build($this->finance);
    $this->payouts->approve($batch, $this->approver);
    $this->payouts->execute($batch->refresh());

    Queue::assertPushed(ExecutePayoutLine::class, 2);

    expect((new ExecutePayoutLine(7))->uniqueId())->toBe('payout-line:7')
        ->and((new ExecutePayoutLine(7))->tries)->toBe(1);
});
