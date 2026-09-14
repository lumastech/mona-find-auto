<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Ledger\Enums\AdjustmentStatus;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Exceptions\AdjustmentNotAllowed;
use App\Modules\Ledger\Exceptions\LedgerException;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\LedgerAdjustment;
use App\Modules\Ledger\Services\LedgerAdjustmentService;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;

beforeEach(function (): void {
    $this->service = app(LedgerAdjustmentService::class);
    $this->balances = app(LedgerBalances::class);

    $this->finance = User::factory()->create();
    $this->finance->assignRole(Role::Finance->value);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::PlatformAdmin->value);
});

/**
 * A balanced two-line draft moving $amount onto a seller's payable.
 *
 * @return array<int, array<string, mixed>>
 */
function adjustmentLines(Seller $seller, int $amount = 5_000): array
{
    return [
        [
            'account' => LedgerAccountCode::RefundsExpense->value,
            'direction' => EntryDirection::Debit->value,
            'amount_ngwee' => $amount,
        ],
        [
            'account' => LedgerAccountCode::SellerPayable->value,
            'direction' => EntryDirection::Credit->value,
            'amount_ngwee' => $amount,
            'subject_id' => $seller->getKey(),
        ],
    ];
}

it('moves no money when drafted', function (): void {
    $seller = Seller::factory()->create();

    $adjustment = $this->service->draft(
        adjustmentLines($seller),
        'Goodwill credit',
        'Agreed with the seller after a delivery failure.',
        $this->finance,
    );

    expect($adjustment->status)->toBe(AdjustmentStatus::Pending)
        ->and($adjustment->total_ngwee)->toBeMoney(5_000)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and($this->balances->sellerPayable($seller))->toBeMoney(0);
});

it('refuses a draft that does not balance', function (): void {
    $seller = Seller::factory()->create();
    $lines = adjustmentLines($seller);
    $lines[1]['amount_ngwee'] = 4_000;

    expect(fn () => $this->service->draft($lines, 'Wonky', 'A reason long enough.', $this->finance))
        ->toThrow(LedgerException::class, 'does not balance');

    expect(LedgerAdjustment::query()->count())->toBe(0);
});

it('refuses to let anyone adjust platform cash by hand', function (): void {
    $lines = [
        [
            'account' => LedgerAccountCode::PlatformCash->value,
            'direction' => EntryDirection::Debit->value,
            'amount_ngwee' => 5_000,
        ],
        [
            'account' => LedgerAccountCode::RefundsExpense->value,
            'direction' => EntryDirection::Credit->value,
            'amount_ngwee' => 5_000,
        ],
    ];

    expect(fn () => $this->service->draft($lines, 'Invent cash', 'A reason long enough.', $this->finance))
        ->toThrow(LedgerException::class, 'cannot be adjusted by hand');
});

it('refuses a line on a per-subject account with no subject', function (): void {
    $lines = adjustmentLines(Seller::factory()->create());
    unset($lines[1]['subject_id']);

    expect(fn () => $this->service->draft($lines, 'No seller', 'A reason long enough.', $this->finance))
        ->toThrow(LedgerException::class, 'must name one');
});

it('posts on approval, and only then', function (): void {
    $seller = Seller::factory()->create();
    $adjustment = $this->service->draft(
        adjustmentLines($seller),
        'Goodwill credit',
        'Agreed with the seller after a delivery failure.',
        $this->finance,
    );

    $this->service->approve($adjustment, $this->admin, 'Checked against the courier report.');

    $entry = JournalEntry::query()->sole();

    expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Approved)
        ->and($adjustment->fresh()->journal_entry_id)->toBe($entry->getKey())
        ->and($entry->recipe)->toBe(PostingRecipe::ManualAdjustment)
        ->and($entry->created_by)->toBe($this->admin->getKey())
        ->and($this->balances->sellerPayable($seller))->toBeMoney(5_000);
});

it('refuses self-approval', function (): void {
    $adjustment = $this->service->draft(
        adjustmentLines(Seller::factory()->create()),
        'Goodwill credit',
        'Agreed with the seller after a delivery failure.',
        $this->admin,
    );

    expect(fn () => $this->service->approve($adjustment, $this->admin))
        ->toThrow(AdjustmentNotAllowed::class, 'somebody other than the person who created it');

    expect(JournalEntry::query()->count())->toBe(0);
});

it('refuses to decide an adjustment twice', function (): void {
    $adjustment = $this->service->draft(
        adjustmentLines(Seller::factory()->create()),
        'Goodwill credit',
        'Agreed with the seller after a delivery failure.',
        $this->finance,
    );

    $this->service->approve($adjustment, $this->admin);

    expect(fn () => $this->service->approve($adjustment->fresh(), $this->admin))
        ->toThrow(AdjustmentNotAllowed::class, 'already');

    expect(JournalEntry::query()->count())->toBe(1);
});

it('posts nothing when rejected', function (): void {
    $seller = Seller::factory()->create();
    $adjustment = $this->service->draft(
        adjustmentLines($seller),
        'Goodwill credit',
        'Agreed with the seller after a delivery failure.',
        $this->finance,
    );

    $this->service->reject($adjustment, $this->admin, 'The courier report says otherwise.');

    expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Rejected)
        ->and($adjustment->fresh()->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and($this->balances->sellerPayable($seller))->toBeMoney(0);
});

it('audits both halves of the control', function (): void {
    $adjustment = $this->service->draft(
        adjustmentLines(Seller::factory()->create()),
        'Goodwill credit',
        'Agreed with the seller after a delivery failure.',
        $this->finance,
    );

    $this->service->approve($adjustment, $this->admin, 'Checked.');

    $drafted = AuditLog::query()->where('action', 'ledger_adjustment.drafted')->sole();
    $approved = AuditLog::query()->where('action', 'ledger_adjustment.approved')->sole();

    expect($drafted->actor_id)->toBe($this->finance->getKey())
        ->and($approved->actor_id)->toBe($this->admin->getKey())
        /* And the posting itself is audited a third time. */
        ->and(AuditLog::query()->where('action', 'ledger.posted')->count())->toBe(1);
});
