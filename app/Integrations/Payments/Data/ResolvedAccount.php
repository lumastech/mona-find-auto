<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Data;

/**
 * A payout destination as the gateway sees it.
 *
 * The name is the gateway's, never the seller's: a seller who types
 * "M. Banda" against an account the bank calls "MULENGA BANDA LTD" has the
 * bank's version stored, because that is the name a failed transfer will be
 * argued about.
 */
final readonly class ResolvedAccount
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $accountName,
        public string $accountNumber,
        public ?string $bankCode = null,
        public ?string $bankName = null,
        public ?string $network = null,
        public array $raw = [],
    ) {}

    public function isMobileMoney(): bool
    {
        return $this->network !== null;
    }
}
