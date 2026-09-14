<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Exceptions\LedgerException;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\LedgerService;
use App\Modules\Ledger\Support\Posting;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use App\Support\Database\ImmutableRecordException;
use App\Support\Money\Money;

/**
 * The invariants every other test in the module leans on.
 */
beforeEach(function (): void {
    $this->ledger = app(LedgerService::class);
    $this->balances = app(LedgerBalances::class);
});

/**
 * A balanced posting of $amount between cash and escrow.
 */
function escrowPosting(Order $order, int $amount, string $key): Posting
{
    return Posting::make(PostingRecipe::EscrowPayment, 'Payment for '.$order->number, $key)
        ->about($order)
        ->debit(LedgerAccountCode::PlatformCash, Money::ofNgwee($amount))
        ->credit(LedgerAccountCode::EscrowHeld, Money::ofNgwee($amount), $order);
}

it('refuses a posting whose debits and credits disagree', function (): void {
    $order = Order::factory()->create();

    $posting = Posting::make(PostingRecipe::EscrowPayment, 'Unbalanced', 'unbalanced:1')
        ->debit(LedgerAccountCode::PlatformCash, Money::ofNgwee(1_000))
        ->credit(LedgerAccountCode::EscrowHeld, Money::ofNgwee(900), $order);

    expect(fn () => $this->ledger->post($posting))
        ->toThrow(LedgerException::class, 'does not balance');

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->count())->toBe(0);
});

it('refuses a posting with a single line', function (): void {
    $posting = Posting::make(PostingRecipe::ManualAdjustment, 'One-sided', 'one-sided:1')
        ->debit(LedgerAccountCode::RefundsExpense, Money::ofNgwee(1_000));

    expect(fn () => $this->ledger->post($posting))
        ->toThrow(LedgerException::class, 'at least two lines');
});

it('refuses a line with a zero or negative amount', function (): void {
    expect(fn () => Posting::make(PostingRecipe::Payout, 'Nothing', 'nothing:1')
        ->debit(LedgerAccountCode::PlatformCash, Money::ofNgwee(0)))
        ->toThrow(LedgerException::class, 'positive amount');

    expect(fn () => Posting::make(PostingRecipe::Payout, 'Backwards', 'backwards:1')
        ->debit(LedgerAccountCode::PlatformCash, Money::ofNgwee(-500)))
        ->toThrow(LedgerException::class, 'Reverse the direction');
});

it('refuses a line on a per-subject account with no subject', function (): void {
    expect(fn () => Posting::make(PostingRecipe::EscrowPayment, 'No subject', 'no-subject:1')
        ->credit(LedgerAccountCode::EscrowHeld, Money::ofNgwee(1_000)))
        ->toThrow(LedgerException::class, 'must name one');
});

it('refuses a subject on a platform-wide account', function (): void {
    $order = Order::factory()->create();

    expect(fn () => Posting::make(PostingRecipe::EscrowPayment, 'Odd subject', 'odd-subject:1')
        ->debit(LedgerAccountCode::PlatformCash, Money::ofNgwee(1_000), $order))
        ->toThrow(LedgerException::class, 'platform-wide account');
});

it('ignores a double post of the same business event', function (): void {
    $order = Order::factory()->create();

    $first = $this->ledger->post(escrowPosting($order, 10_000, 'escrow-payment:order:'.$order->id));
    $second = $this->ledger->post(escrowPosting($order, 10_000, 'escrow-payment:order:'.$order->id));

    expect($second->getKey())->toBe($first->getKey())
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(JournalLine::query()->count())->toBe(2)
        /* And crucially, the balances were not doubled either. */
        ->and($this->balances->escrowHeldFor($order))->toBeMoney(10_000);
});

it('posts different events on the same order separately', function (): void {
    $order = Order::factory()->create();

    $this->ledger->post(escrowPosting($order, 10_000, 'escrow-payment:order:'.$order->id));
    $this->ledger->post(escrowPosting($order, 2_500, 'top-up:order:'.$order->id));

    expect(JournalEntry::query()->count())->toBe(2)
        ->and($this->balances->escrowHeldFor($order))->toBeMoney(12_500);
});

it('forbids writing a journal entry outside a posting', function (): void {
    expect(fn () => JournalEntry::query()->create([
        'idempotency_key' => 'smuggled:1',
        'recipe' => PostingRecipe::ManualAdjustment,
        'description' => 'Straight through Eloquent',
        'total_ngwee' => 1_000,
        'posted_at' => now(),
    ]))->toThrow(LedgerException::class, 'may only be written by LedgerService::post()');
});

it('forbids writing a journal line outside a posting', function (): void {
    expect(fn () => JournalLine::query()->create([
        'journal_entry_id' => 1,
        'ledger_account_id' => LedgerAccount::for(LedgerAccountCode::PlatformCash)->getKey(),
        'direction' => 'debit',
        'amount_ngwee' => 1_000,
        'posted_at' => now(),
    ]))->toThrow(LedgerException::class, 'may only be written by LedgerService::post()');
});

it('refuses to update or delete a posted entry', function (): void {
    $order = Order::factory()->create();
    $entry = $this->ledger->post(escrowPosting($order, 10_000, 'escrow-payment:order:'.$order->id));

    expect(fn () => $entry->update(['description' => 'Rewritten']))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $entry->delete())->toThrow(ImmutableRecordException::class);
    expect(fn () => $entry->lines->first()->delete())->toThrow(ImmutableRecordException::class);
});

it('writes an audit row for every money movement', function (): void {
    $actor = User::factory()->create();
    $order = Order::factory()->create();

    $entry = $this->ledger->post(
        escrowPosting($order, 10_000, 'escrow-payment:order:'.$order->id)->by($actor),
    );

    $audit = AuditLog::query()->where('action', 'ledger.posted')->sole();

    expect($audit->actor_id)->toBe($actor->getKey())
        ->and($audit->after['uuid'])->toBe($entry->uuid)
        ->and($audit->after['total_ngwee'])->toBe(10_000);
});

it('signs balances against the account rather than the direction', function (): void {
    $seller = Seller::factory()->create();

    $this->ledger->post(
        Posting::make(PostingRecipe::DirectPayment, 'Credit the seller', 'credit:1')
            ->debit(LedgerAccountCode::PlatformCash, Money::ofNgwee(10_000))
            ->credit(LedgerAccountCode::SellerPayable, Money::ofNgwee(10_000), $seller),
    );

    /* A credit increases a payable; a debit increases cash. Both read positive. */
    expect($this->balances->sellerPayable($seller))->toBeMoney(10_000)
        ->and($this->balances->forAccount(LedgerAccountCode::PlatformCash))->toBeMoney(10_000);

    $this->ledger->post(
        Posting::make(PostingRecipe::DirectClawback, 'Take it back', 'clawback:1')
            ->debit(LedgerAccountCode::SellerPayable, Money::ofNgwee(15_000), $seller)
            ->credit(LedgerAccountCode::PlatformCash, Money::ofNgwee(15_000)),
    );

    /* Owing the platform reads as a negative payable, which is the point. */
    expect($this->balances->sellerPayable($seller))->toBeMoney(-5_000);
});

it('keeps a per-subject balance and an account-wide one', function (): void {
    $first = Order::factory()->create();
    $second = Order::factory()->create();

    $this->ledger->post(escrowPosting($first, 10_000, 'escrow-payment:order:'.$first->id));
    $this->ledger->post(escrowPosting($second, 4_000, 'escrow-payment:order:'.$second->id));

    expect($this->balances->escrowHeldFor($first))->toBeMoney(10_000)
        ->and($this->balances->escrowHeldFor($second))->toBeMoney(4_000)
        ->and($this->balances->forAccount(LedgerAccountCode::EscrowHeld))->toBeMoney(14_000);
});
