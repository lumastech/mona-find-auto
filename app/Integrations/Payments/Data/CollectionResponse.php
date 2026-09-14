<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Data;

use App\Support\Money\Money;

/**
 * The gateway's view of one money-in attempt, keyed by our own reference.
 */
final readonly class CollectionResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $reference,
        public PaymentStatus $status,
        public Money $amount,
        public ?string $gatewayId = null,
        public ?string $checkoutUrl = null,
        public ?Money $fee = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
