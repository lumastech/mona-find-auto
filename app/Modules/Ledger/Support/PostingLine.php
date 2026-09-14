<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Exceptions\LedgerException;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One side of one movement: an account, a direction and a positive amount.
 *
 * The amount is always positive and the direction says which way it went.
 * A ledger that allows negative amounts allows the same movement to be
 * written two ways, and then "do the debits equal the credits" stops being a
 * question worth asking — a sign error passes it.
 *
 * The subject is what makes a balance answerable: escrow is held for an
 * order, a payable is owed to a seller. Accounts that are broken down that
 * way must name one and platform-wide accounts must not, both checked here
 * rather than left to the recipe that built the line.
 */
final readonly class PostingLine
{
    private function __construct(
        public LedgerAccountCode $account,
        public EntryDirection $direction,
        public Money $amount,
        public ?string $subjectType,
        public ?int $subjectId,
        public ?string $memo,
    ) {}

    public static function debit(
        LedgerAccountCode $account,
        Money $amount,
        ?Model $subject = null,
        ?string $memo = null,
    ): self {
        return self::make($account, EntryDirection::Debit, $amount, $subject, $memo);
    }

    public static function credit(
        LedgerAccountCode $account,
        Money $amount,
        ?Model $subject = null,
        ?string $memo = null,
    ): self {
        return self::make($account, EntryDirection::Credit, $amount, $subject, $memo);
    }

    private static function make(
        LedgerAccountCode $account,
        EntryDirection $direction,
        Money $amount,
        ?Model $subject,
        ?string $memo,
    ): self {
        if (! $amount->isPositive()) {
            throw LedgerException::nonPositiveAmount($account, $amount);
        }

        $expected = $account->subject();

        if ($expected->isRequired() && $subject === null) {
            throw LedgerException::missingSubject($account, $expected);
        }

        if (! $expected->isRequired() && $subject !== null) {
            throw LedgerException::unexpectedSubject($account);
        }

        return new self(
            account: $account,
            direction: $direction,
            amount: $amount,
            subjectType: $subject?->getMorphClass(),
            subjectId: $subject === null ? null : (int) $subject->getKey(),
            memo: $memo,
        );
    }

    public function isDebit(): bool
    {
        return $this->direction === EntryDirection::Debit;
    }

    /**
     * The amount signed against this account's normal balance: positive when
     * the line increases the account, negative when it reduces it.
     */
    public function signedAmount(): Money
    {
        return $this->direction === $this->account->normalBalance()
            ? $this->amount
            : $this->amount->negated();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'direction' => $this->direction,
            'amount_ngwee' => $this->amount->ngwee,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'memo' => $this->memo,
        ];
    }
}
