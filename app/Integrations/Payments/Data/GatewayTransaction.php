<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Data;

use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * A single credit or debit on MonaFind's gateway account.
 *
 * The gateway's own bank statement. Reconciliation compares it against the
 * platform_cash movements in the ledger: anything on one side and not the
 * other is either money the books have not heard of or a posting that never
 * really happened.
 */
final readonly class GatewayTransaction
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public Money $amount,
        public string $direction,
        public ?string $narration = null,
        public ?Carbon $occurredAt = null,
        public array $raw = [],
    ) {}

    public function isCredit(): bool
    {
        return $this->direction === 'credit';
    }

    /**
     * The amount signed the way the ledger reads it: credits into the
     * platform's cash are positive, debits out of it negative.
     */
    public function signedAmount(): Money
    {
        return $this->isCredit() ? $this->amount : $this->amount->negated();
    }
}
