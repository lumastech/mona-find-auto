<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Exceptions;

use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\LedgerSubject;
use App\Support\Money\Money;
use RuntimeException;

/**
 * A posting the ledger refused.
 *
 * Every one of these is a programming error rather than a user error: the
 * ledger's invariants are not conditions a caller is allowed to fail. They
 * are never rendered to a buyer, so the messages say what is wrong rather
 * than what to do about it.
 */
class LedgerException extends RuntimeException
{
    public static function unbalanced(Money $debits, Money $credits): self
    {
        return new self(sprintf(
            'Journal entry does not balance: debits %s, credits %s (difference %s).',
            $debits->format(),
            $credits->format(),
            $debits->minus($credits)->format(),
        ));
    }

    public static function noLines(): self
    {
        return new self('A journal entry must have at least two lines.');
    }

    public static function nonPositiveAmount(LedgerAccountCode $account, Money $amount): self
    {
        return new self(sprintf(
            'Journal line on [%s] must carry a positive amount, got %s. Reverse the direction instead of signing the amount.',
            $account->value,
            $amount->format(),
        ));
    }

    public static function missingSubject(LedgerAccountCode $account, LedgerSubject $subject): self
    {
        return new self(sprintf(
            'Account [%s] is broken down %s, so every line on it must name one.',
            $account->value,
            $subject->label(),
        ));
    }

    public static function unexpectedSubject(LedgerAccountCode $account): self
    {
        return new self(sprintf(
            'Account [%s] is a platform-wide account and cannot carry a subject.',
            $account->value,
        ));
    }

    public static function missingIdempotencyKey(): self
    {
        return new self('Every posting needs an idempotency key naming the business event it records.');
    }

    public static function directWrite(string $model): self
    {
        return new self(sprintf(
            '[%s] rows may only be written by LedgerService::post(). Direct model writes bypass the balance check, the idempotency key and the materialised balances.',
            $model,
        ));
    }

    public static function unknownAccount(string $code): self
    {
        return new self(sprintf(
            'Ledger account [%s] is not in the chart. Run the LedgerAccountSeeder.',
            $code,
        ));
    }
}
