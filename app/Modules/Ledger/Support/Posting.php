<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Models\User;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Exceptions\LedgerException;
use App\Modules\Ledger\Services\LedgerService;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A complete, balanced movement, ready to be handed to LedgerService::post().
 *
 * Built rather than assembled from an array so that the three things a
 * posting cannot go without are impossible to forget: the recipe that
 * produced it, what it is about, and the idempotency key.
 *
 * The key is the load-bearing part. It names the business event — "the escrow
 * on order 4012 was released", not "somebody called this method" — so that a
 * webhook delivered twice, a queued listener retried after a timeout and an
 * operator clicking twice all resolve to the same key and post once. Deriving
 * it from a timestamp or a uuid generated at call time would satisfy the
 * unique index and defeat its entire purpose.
 *
 * @see LedgerService::post()
 */
final class Posting
{
    /** @var array<int, PostingLine> */
    private array $lines = [];

    private ?Model $reference = null;

    private ?User $actor = null;

    private ?CarbonInterface $occurredAt = null;

    /** @var array<string, mixed> */
    private array $context = [];

    private function __construct(
        public readonly PostingRecipe $recipe,
        public readonly string $description,
        public readonly string $idempotencyKey,
    ) {
        if (trim($idempotencyKey) === '') {
            throw LedgerException::missingIdempotencyKey();
        }
    }

    public static function make(PostingRecipe $recipe, string $description, string $idempotencyKey): self
    {
        return new self($recipe, $description, $idempotencyKey);
    }

    /**
     * What this entry is about: the order, payout, refund or adjustment that
     * caused it. Stored polymorphically so the ledger browser can jump
     * straight from a movement to the thing that moved it.
     */
    public function about(?Model $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * Who caused it. Null for everything the platform did on its own, which
     * is most postings — a webhook and a nightly sweep have no author.
     */
    public function by(?User $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    /**
     * When the money moved, if that is not now. Backdating is for reconciling
     * against a gateway's own timestamps, never for rewriting a period.
     */
    public function at(?CarbonInterface $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): self
    {
        $this->context = [...$this->context, ...$context];

        return $this;
    }

    public function debit(LedgerAccountCode $account, Money $amount, ?Model $subject = null, ?string $memo = null): self
    {
        return $this->addLine(PostingLine::debit($account, $amount, $subject, $memo));
    }

    public function credit(LedgerAccountCode $account, Money $amount, ?Model $subject = null, ?string $memo = null): self
    {
        return $this->addLine(PostingLine::credit($account, $amount, $subject, $memo));
    }

    /**
     * Add a line only if it carries money.
     *
     * The recipes lean on this heavily: a seller on a zero add-on fee should
     * produce an entry with no add-on line at all, not a line for nothing.
     * Zero-amount lines are rejected outright by PostingLine, which is what
     * makes this the deliberate way to express "if there is any".
     */
    public function debitIfAny(LedgerAccountCode $account, Money $amount, ?Model $subject = null, ?string $memo = null): self
    {
        return $amount->isPositive() ? $this->debit($account, $amount, $subject, $memo) : $this;
    }

    public function creditIfAny(LedgerAccountCode $account, Money $amount, ?Model $subject = null, ?string $memo = null): self
    {
        return $amount->isPositive() ? $this->credit($account, $amount, $subject, $memo) : $this;
    }

    private function addLine(PostingLine $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    /**
     * @return array<int, PostingLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function referenceType(): ?string
    {
        return $this->reference?->getMorphClass();
    }

    public function referenceId(): ?int
    {
        return $this->reference === null ? null : (int) $this->reference->getKey();
    }

    public function referenceModel(): ?Model
    {
        return $this->reference;
    }

    public function actor(): ?User
    {
        return $this->actor;
    }

    public function occurredAt(): CarbonInterface
    {
        return $this->occurredAt ?? now();
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function totalDebits(): Money
    {
        return $this->sum(true);
    }

    public function totalCredits(): Money
    {
        return $this->sum(false);
    }

    /**
     * Refuse anything that is not a complete double entry.
     *
     * Called by LedgerService before a row is written, and deliberately not
     * by the builder as lines are added: a recipe assembles its lines one at
     * a time and is unbalanced for most of that.
     */
    public function assertBalanced(): void
    {
        if (count($this->lines) < 2) {
            throw LedgerException::noLines();
        }

        $debits = $this->totalDebits();
        $credits = $this->totalCredits();

        if (! $debits->equals($credits)) {
            throw LedgerException::unbalanced($debits, $credits);
        }
    }

    private function sum(bool $debits): Money
    {
        return Money::sum(...array_map(
            static fn (PostingLine $line): Money => $line->amount,
            array_filter($this->lines, static fn (PostingLine $line): bool => $line->isDebit() === $debits),
        ));
    }
}
