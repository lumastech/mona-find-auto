<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Data;

use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * One payout from the gateway into MonaFind's own bank account.
 *
 * A collection and its settlement are different events days apart: the buyer's
 * money succeeds today and lands, net of Lenco's charge, tomorrow. Nightly
 * reconciliation needs both, which is why the collection's reference is
 * carried here — it is the only thread back to an order.
 */
final readonly class SettlementRecord
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public Money $amountSettled,
        public string $status,
        public ?string $collectionReference = null,
        public ?Money $collectionAmount = null,
        public ?Carbon $settledAt = null,
        public array $raw = [],
    ) {}

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }
}
