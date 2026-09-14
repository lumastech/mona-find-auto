<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Support\Posting;
use App\Modules\Ledger\Support\PostingGuard;
use App\Modules\Ledger\Support\PostingLine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes money into the ledger.
 *
 * Everything the module guarantees is enforced on the way through this one
 * method, which is why nothing else may create a journal row — see
 * PostingGuard.
 *
 * It balances. `Posting::assertBalanced()` runs before a row is written, so
 * an entry whose debits and credits disagree never exists at all rather than
 * existing and needing a correction that, on an append-only table, can only
 * ever be another entry.
 *
 * It posts once. Every posting names the business event it records —
 * "escrow-release:order:4012" — and that key carries a unique index. Lenco
 * delivers webhooks more than once as a matter of course, queued listeners
 * are retried after timeouts, and operators click twice; each of those
 * resolves to the same key and the second attempt returns the entry the
 * first one wrote. The check is the database's rather than PHP's on purpose:
 * two deliveries can be in flight in different workers at the same instant,
 * and "select, then insert" loses that race.
 *
 * And it keeps the cached balances in step, inside the same transaction as
 * the lines, so the two can never be half-written with respect to each other.
 */
class LedgerService
{
    public function __construct(private readonly LedgerBalances $balances) {}

    /**
     * Write a balanced movement.
     *
     * Returns the entry that records the event — which, on a repeat, is the
     * one that was already there. Callers can tell the difference by whether
     * they asked: `wasRecentlyCreated` is false on a replay.
     */
    public function post(Posting $posting): JournalEntry
    {
        $posting->assertBalanced();

        $existing = $this->find($posting->idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): JournalEntry => PostingGuard::open(
                fn (): JournalEntry => $this->write($posting),
            ));
        } catch (UniqueConstraintViolationException $exception) {
            /*
             * Another worker got there first between the check above and the
             * insert. That is the race the unique index exists to lose
             * safely: the event is recorded once, and this caller is handed
             * the entry that records it.
             */
            return $this->find($posting->idempotencyKey) ?? throw $exception;
        }
    }

    /**
     * The entry recording a business event, if it has been posted.
     */
    public function find(string $idempotencyKey): ?JournalEntry
    {
        return JournalEntry::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    public function hasPosted(string $idempotencyKey): bool
    {
        return JournalEntry::query()->where('idempotency_key', $idempotencyKey)->exists();
    }

    /**
     * The insert itself. Runs inside the transaction and the posting guard.
     */
    private function write(Posting $posting): JournalEntry
    {
        $actor = $posting->actor();
        $postedAt = $posting->occurredAt();

        $entry = JournalEntry::query()->create([
            'idempotency_key' => $posting->idempotencyKey,
            'recipe' => $posting->recipe,
            'description' => $posting->description,
            'reference_type' => $posting->referenceType(),
            'reference_id' => $posting->referenceId(),
            'created_by' => $actor?->getKey(),
            'actor_label' => $actor->name ?? 'System',
            'total_ngwee' => $posting->totalDebits()->ngwee,
            'context' => $posting->context() === [] ? null : $posting->context(),
            'posted_at' => $postedAt,
            'created_at' => now(),
        ]);

        foreach ($posting->lines() as $line) {
            $this->writeLine($entry, $line, $postedAt);
        }

        /*
         * Every money movement leaves an audit row, alongside the entry
         * itself. The two answer different questions: the ledger says what
         * moved, the audit trail says who was standing there when it did.
         */
        audit(
            $actor,
            'ledger.posted',
            $entry,
            null,
            [
                'uuid' => $entry->uuid,
                'recipe' => $entry->recipe->value,
                'total_ngwee' => $entry->total_ngwee->ngwee,
                'idempotency_key' => $entry->idempotency_key,
            ],
            $posting->description,
            ['reference' => $entry->referenceLabel()],
        );

        return $entry->load('lines');
    }

    private function writeLine(JournalEntry $entry, PostingLine $line, mixed $postedAt): void
    {
        $account = LedgerAccount::for($line->account);

        $row = JournalLine::query()->create([
            'journal_entry_id' => $entry->getKey(),
            'ledger_account_id' => $account->getKey(),
            ...$line->toAttributes(),
            'posted_at' => $postedAt,
            'created_at' => now(),
        ]);

        $this->balances->apply($line, $account, $row);
    }
}
