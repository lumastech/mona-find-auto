<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Data;

use App\Support\Money\Money;

/**
 * The gateway's view of one money-out attempt (a seller payout).
 */
final readonly class TransferResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $reference,
        public PaymentStatus $status,
        public Money $amount,
        public ?string $gatewayId = null,
        public ?Money $fee = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
