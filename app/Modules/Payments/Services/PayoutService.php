<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Integrations\Payments\Data\ResolvedAccount;
use App\Integrations\Payments\Exceptions\LencoRequestFailed;
use App\Models\User;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Payments\Enums\PayoutBatchStatus;
use App\Modules\Payments\Enums\PayoutLineStatus;
use App\Modules\Payments\Exceptions\PayoutNotAllowed;
use App\Modules\Payments\Jobs\ExecutePayoutLine;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Models\PayoutLine;
use App\Modules\Payments\Notifications\PayoutSentNotification;
use App\Modules\Sellers\Models\PayoutAccount;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paying sellers.
 *
 * ## The shape of a run
 *
 * Build → approve → execute, and the three are deliberately separate steps
 * done by different actors. Building is arithmetic over the ledger and can be
 * done by a schedule. Approving is a person accepting responsibility for a
 * total. Executing is queued work that talks to Lenco one line at a time.
 *
 * ## Dual control
 *
 * Whoever prepared a batch cannot approve it. This is the largest outbound
 * money movement on the platform, and one compromised account should not be
 * enough to drain it into an attacker's wallet.
 *
 * ## Why lines are independent
 *
 * Each line is its own transfer with its own reference, and each succeeds or
 * fails alone. A batch of forty where the eleventh bounces pays the other
 * thirty-nine. The eleventh reverts that seller's payable — and only that
 * seller's — so they are picked up by the next run automatically.
 *
 * ## The three outcomes, and why there are three
 *
 * Paid, failed, and UNRESOLVED. The third exists because a transfer call that
 * times out may well have moved money. Marking it failed would revert a
 * payable that was really paid and hand the seller the same money twice on
 * the next run, so an unresolved line reverts nothing and waits for a human.
 *
 * ## The ledger is posted on confirmation, never on send
 *
 * `recordPayout` runs when Lenco confirms the transfer, not when we ask for
 * it. Posting on send would book a payout that might still fail.
 */
class PayoutService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LedgerBalances $balances,
        private readonly OrderPostingService $posting,
    ) {}

    /**
     * Build a batch from every seller currently owed money.
     *
     * A seller is included when their payable is positive AND they have a
     * default payout account. A seller with no account is skipped silently
     * rather than added as a line that must fail — their money stays payable
     * and they are picked up whenever they add one.
     *
     * The minimum exists because a K2.00 payout can cost more in gateway fees
     * than it moves; small balances roll forward.
     */
    public function build(?User $preparedBy = null, ?Money $minimum = null): PayoutBatch
    {
        $minimum ??= Money::ofNgwee((int) settings('payouts.minimum_ngwee', 5_000));

        return DB::transaction(function () use ($preparedBy, $minimum): PayoutBatch {
            $batch = PayoutBatch::create([
                'reference' => $this->nextBatchReference(),
                'status' => PayoutBatchStatus::AwaitingApproval,
                'prepared_by' => $preparedBy?->getKey(),
                'scheduled_for' => now(),
            ]);

            $total = Money::zero();
            $count = 0;

            foreach ($this->payableSellers() as $seller) {
                $amount = $this->balances->sellerPayable($seller);

                if ($amount->lessThan($minimum)) {
                    continue;
                }

                $account = $this->defaultAccountFor($seller);

                if ($account === null) {
                    continue;
                }

                PayoutLine::create([
                    'payout_batch_id' => $batch->getKey(),
                    'seller_id' => $seller->getKey(),
                    'payout_account_id' => $account->getKey(),
                    'reference' => PayoutLine::referenceFor($batch->getKey(), $seller->getKey()),
                    'status' => PayoutLineStatus::Pending,
                    'amount_ngwee' => $amount,
                    'method' => $account->method,
                    'lenco_recipient_id' => $account->lenco_recipient_id,
                ]);

                $total = $total->plus($amount);
                $count++;
            }

            $batch->forceFill(['line_count' => $count, 'total_ngwee' => $total])->save();

            audit($preparedBy, 'payouts.batch.built', $batch, null, [
                'line_count' => $count,
                'total_ngwee' => $total->ngwee,
            ]);

            return $batch->refresh();
        });
    }

    /**
     * Release a batch. The second half of dual control.
     *
     * @throws PayoutNotAllowed
     */
    public function approve(PayoutBatch $batch, User $approver, ?string $note = null): PayoutBatch
    {
        if (! $batch->status->isApprovable()) {
            throw PayoutNotAllowed::notApprovable($batch->status);
        }

        if ($batch->prepared_by === $approver->getKey()) {
            throw PayoutNotAllowed::selfApproval();
        }

        if ($batch->line_count === 0) {
            throw PayoutNotAllowed::empty();
        }

        $before = ['status' => $batch->status->value];

        $batch->forceFill([
            'status' => PayoutBatchStatus::Approved,
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
            'approval_note' => $note,
        ])->save();

        audit($approver, 'payouts.batch.approved', $batch, $before, [
            'status' => PayoutBatchStatus::Approved->value,
            'total_ngwee' => $batch->total_ngwee->ngwee,
            'line_count' => $batch->line_count,
        ], $note);

        return $batch;
    }

    /**
     * Queue every line of an approved batch.
     *
     * One job per line rather than one for the batch, so a line that throws
     * takes only itself down and is retried on its own.
     */
    public function execute(PayoutBatch $batch): PayoutBatch
    {
        if ($batch->status !== PayoutBatchStatus::Approved) {
            throw PayoutNotAllowed::notApproved($batch->status);
        }

        $batch->forceFill([
            'status' => PayoutBatchStatus::Processing,
            'started_at' => now(),
        ])->save();

        $batch->lines()
            ->where('status', PayoutLineStatus::Pending)
            ->get()
            ->each(fn (PayoutLine $line) => ExecutePayoutLine::dispatch($line->getKey()));

        return $batch;
    }

    /**
     * Send one line's money.
     *
     * Re-resolves the destination account at the gateway first and blocks the
     * line if the name has changed. An account number that has quietly moved
     * to somebody else is cheap to catch here and close to impossible to
     * recover from afterwards.
     */
    public function sendLine(PayoutLine $line): PayoutLine
    {
        if ($line->status !== PayoutLineStatus::Pending) {
            return $line;
        }

        $account = $line->account;

        if ($account === null) {
            return $this->blockLine($line, __('The payout account has been removed.'));
        }

        $resolved = $this->reResolve($account);

        if ($resolved === null) {
            return $this->blockLine($line, __('The gateway no longer recognises this account.'));
        }

        if (! $this->namesMatch($resolved->accountName, $account->resolved_name)) {
            return $this->blockLine($line, __(
                'The account is now registered to :now, not :before.',
                ['now' => $resolved->accountName, 'before' => (string) $account->resolved_name],
            ));
        }

        try {
            $transfer = $this->gateway->initiateTransfer(
                $line->amount_ngwee,
                $line->reference,
                $line->recipientPayload(),
                ['seller_id' => $line->seller_id, 'batch' => $line->batch->reference],
            );
        } catch (LencoRequestFailed $exception) {
            /*
             * We do not know whether the money left. Deliberately NOT failed:
             * see the class docblock. A human decides.
             */
            return $this->markUnresolved($line, $exception->getMessage());
        }

        $line->forceFill([
            'status' => PayoutLineStatus::Sent,
            'lenco_transfer_id' => $transfer->gatewayId,
            'resolved_name_at_payout' => $resolved->accountName,
            'sent_at' => now(),
        ])->save();

        /* Some transfers settle instantly and never send a webhook. */
        if ($transfer->status === PaymentStatus::Successful) {
            return $this->settleLine($line->refresh(), $transfer->fee);
        }

        if ($transfer->status === PaymentStatus::Failed) {
            return $this->failLine($line->refresh(), $transfer->failureReason ?? __('The transfer was rejected.'));
        }

        return $line->refresh();
    }

    /**
     * A transfer webhook or status poll came back. Act on it.
     */
    public function syncLineFromGateway(string $reference): ?PayoutLine
    {
        $line = PayoutLine::query()->where('reference', $reference)->first();

        if ($line === null || $line->status->isFinal()) {
            return $line;
        }

        $transfer = $this->gateway->fetchTransfer($reference);

        return match ($transfer->status) {
            PaymentStatus::Successful => $this->settleLine($line, $transfer->fee),
            PaymentStatus::Failed, PaymentStatus::Reversed => $this->failLine(
                $line,
                $transfer->failureReason ?? __('The transfer was rejected.'),
            ),
            PaymentStatus::Pending => $line,
        };
    }

    /**
     * The money reached the seller. Post it and close the line.
     *
     * The ledger key names the line, not the moment, so a webhook and a poll
     * that both confirm the same transfer post exactly one entry.
     */
    public function settleLine(PayoutLine $line, ?Money $fee = null): PayoutLine
    {
        if ($line->status === PayoutLineStatus::Paid) {
            return $line;
        }

        return DB::transaction(function () use ($line, $fee): PayoutLine {
            $entry = $this->posting->recordPayout(
                seller: $line->seller,
                amount: $line->amount_ngwee,
                eventKey: 'payout:line:'.$line->getKey(),
                fee: $fee,
                reference: $line,
            );

            $line->forceFill([
                'status' => PayoutLineStatus::Paid,
                'fee_ngwee' => $fee,
                'journal_entry_id' => $entry->getKey(),
                'settled_at' => now(),
            ])->save();

            $this->refreshBatchTotals($line->batch);

            audit(null, 'payouts.line.paid', $line, null, [
                'amount_ngwee' => $line->amount_ngwee->ngwee,
                'seller_id' => $line->seller_id,
                'journal_entry_id' => $entry->getKey(),
            ]);

            $line = $line->refresh();

            /*
             * On settlement, not on dispatch. Money handed to Lenco and not
             * yet confirmed is money the seller cannot spend, and saying it
             * has arrived before it has sends them to a bank counter.
             */
            $line->seller->user->notify(new PayoutSentNotification($line));

            return $line;
        });
    }

    /**
     * The transfer bounced. The seller keeps their payable.
     *
     * Nothing is reversed in the ledger because nothing was ever posted — the
     * payout entry is written on confirmation, so a failed line simply never
     * had one. The payable was never reduced and needs no putting back, which
     * is the cleanest possible answer to "what happens to a failed payout".
     */
    public function failLine(PayoutLine $line, string $reason): PayoutLine
    {
        if ($line->status->isFinal()) {
            return $line;
        }

        $line->forceFill([
            'status' => PayoutLineStatus::Failed,
            'failure_reason' => $reason,
            'failed_at' => now(),
        ])->save();

        $this->refreshBatchTotals($line->batch);

        audit(null, 'payouts.line.failed', $line, null, [
            'amount_ngwee' => $line->amount_ngwee->ngwee,
            'seller_id' => $line->seller_id,
            'reason' => $reason,
        ], $reason);

        return $line->refresh();
    }

    /**
     * The line never left. Same effect as a failure, different reason.
     */
    private function blockLine(PayoutLine $line, string $reason): PayoutLine
    {
        $line->forceFill([
            'status' => PayoutLineStatus::Blocked,
            'block_reason' => $reason,
            'failed_at' => now(),
        ])->save();

        $this->refreshBatchTotals($line->batch);

        audit(null, 'payouts.line.blocked', $line, null, [
            'seller_id' => $line->seller_id,
            'reason' => $reason,
        ], $reason);

        return $line->refresh();
    }

    /**
     * We do not know what happened. Leave it alone and flag it.
     */
    private function markUnresolved(PayoutLine $line, string $reason): PayoutLine
    {
        $line->forceFill([
            'status' => PayoutLineStatus::Unresolved,
            'failure_reason' => $reason,
            'sent_at' => now(),
        ])->save();

        $this->refreshBatchTotals($line->batch);

        audit(null, 'payouts.line.unresolved', $line, null, [
            'seller_id' => $line->seller_id,
            'reason' => $reason,
        ], $reason);

        return $line->refresh();
    }

    /**
     * Roll the line outcomes up onto the batch.
     *
     * `total_ngwee` is deliberately left alone — it is what the approver
     * signed off and must not drift afterwards.
     */
    private function refreshBatchTotals(PayoutBatch $batch): void
    {
        $lines = $batch->lines()->get();

        $paid = Money::sum(...$lines
            ->where('status', PayoutLineStatus::Paid)
            ->map(static fn (PayoutLine $line): Money => $line->amount_ngwee)
            ->all());

        $failed = Money::sum(...$lines
            ->filter(static fn (PayoutLine $line): bool => in_array(
                $line->status,
                [PayoutLineStatus::Failed, PayoutLineStatus::Blocked],
                true,
            ))
            ->map(static fn (PayoutLine $line): Money => $line->amount_ngwee)
            ->all());

        $settled = $lines->every(static fn (PayoutLine $line): bool => $line->status->isFinal()
            || $line->status === PayoutLineStatus::Unresolved);

        $batch->forceFill([
            'paid_ngwee' => $paid,
            'failed_ngwee' => $failed,
            'status' => $settled && $batch->status === PayoutBatchStatus::Processing
                ? PayoutBatchStatus::Completed
                : $batch->status,
            'completed_at' => $settled && $batch->completed_at === null ? now() : $batch->completed_at,
        ])->save();
    }

    /**
     * Cancel a batch that has not started moving money.
     */
    public function cancel(PayoutBatch $batch, User $actor, string $reason): PayoutBatch
    {
        if ($batch->status->hasStarted()) {
            throw PayoutNotAllowed::alreadyStarted();
        }

        $before = ['status' => $batch->status->value];

        $batch->forceFill([
            'status' => PayoutBatchStatus::Cancelled,
            'cancellation_reason' => $reason,
        ])->save();

        audit($actor, 'payouts.batch.cancelled', $batch, $before, [
            'status' => PayoutBatchStatus::Cancelled->value,
        ], $reason);

        return $batch;
    }

    /**
     * Sellers with a positive payable balance.
     *
     * @return Collection<int, Seller>
     */
    private function payableSellers(): Collection
    {
        /** @var Collection<int, Seller> $sellers */
        $sellers = Seller::query()
            ->whereHas('payoutAccounts')
            ->get()
            ->filter(fn (Seller $seller): bool => $this->balances->sellerPayable($seller)->isPositive())
            ->values();

        return $sellers;
    }

    private function defaultAccountFor(Seller $seller): ?PayoutAccount
    {
        return $seller->payoutAccounts()->where('is_default', true)->first()
            ?? $seller->payoutAccounts()->oldest('id')->first();
    }

    /**
     * Ask the gateway again what this account is called.
     */
    private function reResolve(PayoutAccount $account): ?ResolvedAccount
    {
        return $account->method->isBank()
            ? $this->gateway->resolveBankAccount((string) $account->account_number, (string) $account->bank_code)
            : $this->gateway->resolveMobileMoney((string) $account->mobile_number, (string) $account->network?->value);
    }

    /**
     * Whether two account names are the same name.
     *
     * Compared loosely — case, punctuation and runs of spaces ignored —
     * because banks are inconsistent about all three and a payout blocked
     * over "MULENGA BANDA LTD" against "Mulenga Banda Ltd." helps nobody.
     * What it still catches is the case that matters: a different person.
     */
    private function namesMatch(string $current, ?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return true;
        }

        $normalise = static fn (string $name): string => Str::of($name)
            ->lower()
            ->replaceMatches('/[^a-z0-9 ]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();

        return $normalise($current) === $normalise($stored);
    }

    /**
     * PB-20260911-1, and the next one that day is -2.
     */
    private function nextBatchReference(): string
    {
        $prefix = 'PB-'.now()->format('Ymd');

        $today = PayoutBatch::query()->where('reference', 'like', $prefix.'%')->count();

        return sprintf('%s-%d', $prefix, $today + 1);
    }
}
