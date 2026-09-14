<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Models\User;
use App\Modules\Ledger\Enums\AdjustmentStatus;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Exceptions\AdjustmentNotAllowed;
use App\Modules\Ledger\Exceptions\LedgerException;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\LedgerAdjustment;
use App\Modules\Ledger\Support\Posting;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Manual corrections, under dual control.
 *
 * Every other entry in the ledger is a consequence: a buyer paid, a window
 * closed, a moderator decided. This is the one route by which a person moves
 * money because they say so — which is why it takes two of them, and why
 * both halves are audited separately.
 *
 * Drafting posts nothing. The lines sit as JSON on the adjustment row until
 * somebody other than their author approves, and only then does anything
 * reach the ledger. A journal line that exists but has moved no money would
 * be precisely the ambiguity the whole module is built to avoid.
 *
 * The draft is checked for balance at draft time as well as at approval, so
 * an adjustment that could never post is rejected in front of the person who
 * can still fix it rather than in front of the person approving it.
 */
class LedgerAdjustmentService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Draft an adjustment. Moves no money.
     *
     * @param  array<int, array{account: string, direction: string, amount_ngwee: int, subject_id?: int|string|null, memo?: string|null}>  $lines
     */
    public function draft(array $lines, string $description, string $reason, User $author): LedgerAdjustment
    {
        $posting = $this->buildPosting($lines, $description, 'draft');
        $posting->assertBalanced();

        $adjustment = LedgerAdjustment::query()->create([
            'status' => AdjustmentStatus::Pending,
            'description' => $description,
            'reason' => $reason,
            'lines' => $this->normalise($lines),
            'total_ngwee' => $posting->totalDebits()->ngwee,
            'created_by' => $author->getKey(),
        ]);

        audit(
            $author,
            'ledger_adjustment.drafted',
            $adjustment,
            null,
            ['total_ngwee' => $adjustment->total_ngwee->ngwee, 'lines' => $adjustment->lines],
            $reason,
        );

        return $adjustment;
    }

    /**
     * Approve a draft and post it.
     *
     * Refuses self-approval outright rather than leaving it to the console to
     * hide the button. The whole control is that two people saw it, and a
     * rule enforced only in the interface is not a control.
     *
     * @throws AdjustmentNotAllowed
     */
    public function approve(LedgerAdjustment $adjustment, User $approver, ?string $note = null): LedgerAdjustment
    {
        if (! $adjustment->isPending()) {
            throw AdjustmentNotAllowed::alreadyDecided($adjustment);
        }

        if ($adjustment->created_by === $approver->getKey()) {
            throw AdjustmentNotAllowed::selfApproval();
        }

        return DB::transaction(function () use ($adjustment, $approver, $note): LedgerAdjustment {
            $entry = $this->post($adjustment, $approver);

            $adjustment->forceFill([
                'status' => AdjustmentStatus::Approved,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
                'journal_entry_id' => $entry->getKey(),
            ])->save();

            audit(
                $approver,
                'ledger_adjustment.approved',
                $adjustment,
                ['status' => AdjustmentStatus::Pending->value],
                ['status' => AdjustmentStatus::Approved->value, 'journal_entry' => $entry->uuid],
                $note ?? $adjustment->reason,
            );

            return $adjustment;
        });
    }

    /**
     * Turn a draft down. Nothing is posted, and the row stays as the record
     * that somebody asked and somebody else said no.
     *
     * @throws AdjustmentNotAllowed
     */
    public function reject(LedgerAdjustment $adjustment, User $approver, ?string $note = null): LedgerAdjustment
    {
        if (! $adjustment->isPending()) {
            throw AdjustmentNotAllowed::alreadyDecided($adjustment);
        }

        $adjustment->forceFill([
            'status' => AdjustmentStatus::Rejected,
            'decided_by' => $approver->getKey(),
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        audit(
            $approver,
            'ledger_adjustment.rejected',
            $adjustment,
            ['status' => AdjustmentStatus::Pending->value],
            ['status' => AdjustmentStatus::Rejected->value],
            $note,
        );

        return $adjustment;
    }

    private function post(LedgerAdjustment $adjustment, User $approver): JournalEntry
    {
        $posting = $this->buildPosting(
            $adjustment->lines,
            $adjustment->description,
            'adjustment:'.$adjustment->getKey(),
        )
            ->about($adjustment)
            ->by($approver)
            ->withContext(['reason' => $adjustment->reason, 'drafted_by' => $adjustment->created_by]);

        return $this->ledger->post($posting);
    }

    /**
     * Build the posting a set of draft lines describes.
     *
     * The subject is derived from the account rather than taken from the
     * form: escrow is always held for an order and a payable is always owed
     * to a seller, so there is no way to name a subject of the wrong kind.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function buildPosting(array $lines, string $description, string $key): Posting
    {
        $posting = Posting::make(PostingRecipe::ManualAdjustment, $description, $key);

        foreach ($lines as $line) {
            $account = LedgerAccountCode::from((string) $line['account']);

            if (! $account->isManuallyAdjustable()) {
                throw new LedgerException(sprintf(
                    'Account [%s] cannot be adjusted by hand: money does not enter or leave Lenco because somebody typed a journal entry.',
                    $account->value,
                ));
            }

            $subject = $account->subject()->resolve($line['subject_id'] ?? null);

            if ($account->subject()->isRequired() && $subject === null) {
                throw LedgerException::missingSubject($account, $account->subject());
            }

            $amount = Money::ofNgwee((int) $line['amount_ngwee']);
            $memo = isset($line['memo']) ? (string) $line['memo'] : null;

            if (EntryDirection::from((string) $line['direction']) === EntryDirection::Debit) {
                $posting->debit($account, $amount, $subject, $memo);
            } else {
                $posting->credit($account, $amount, $subject, $memo);
            }
        }

        return $posting;
    }

    /**
     * Store the lines with the subject spelled out, so the console can render
     * a draft without resolving anything.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalise(array $lines): array
    {
        return array_values(array_map(static function (array $line): array {
            $account = LedgerAccountCode::from((string) $line['account']);
            $subject = $account->subject()->resolve($line['subject_id'] ?? null);

            return [
                'account' => $account->value,
                'direction' => EntryDirection::from((string) $line['direction'])->value,
                'amount_ngwee' => (int) $line['amount_ngwee'],
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject === null ? null : (int) $subject->getKey(),
                'subject_label' => $subject?->getRouteKey(),
                'memo' => isset($line['memo']) ? (string) $line['memo'] : null,
            ];
        }, $lines));
    }
}
