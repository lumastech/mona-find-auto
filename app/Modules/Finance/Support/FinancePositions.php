<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support;

use App\Support\Money\Money;

/**
 * Where the money stands at a moment — the other half of FinanceTotals.
 *
 * A position is cumulative: every line ever posted to the account, up to and
 * including the end of the window. That is why these are read separately from
 * the flows rather than falling out of the same query. "Escrow held in
 * September" is not a meaningful number; "escrow held at the end of September"
 * is, and it includes money taken in August.
 *
 * All four are signed against the account's normal balance, so a negative
 * payable means sellers collectively owe the platform after clawbacks.
 */
final readonly class FinancePositions
{
    public function __construct(
        public Money $platformCash,
        public Money $escrowHeld,
        public Money $payablesOutstanding,
        public Money $reserveHeld,
        public Money $vatOwed,
    ) {}

    public static function zero(): self
    {
        return new self(Money::zero(), Money::zero(), Money::zero(), Money::zero(), Money::zero());
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'platformCashNgwee' => $this->platformCash->ngwee,
            'escrowHeldNgwee' => $this->escrowHeld->ngwee,
            'payablesOutstandingNgwee' => $this->payablesOutstanding->ngwee,
            'reserveHeldNgwee' => $this->reserveHeld->ngwee,
            'vatOwedNgwee' => $this->vatOwed->ngwee,
        ];
    }
}
