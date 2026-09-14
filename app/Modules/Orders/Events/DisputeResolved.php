<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Models\OrderDispute;
use App\Support\Money\Money;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A moderator has decided, and the money can move.
 *
 * Payments consumes this and does exactly what the resolution says: release
 * the escrow, refund part of it, or refund all of it. The amount is carried
 * explicitly rather than left to be recomputed, because the figure a
 * moderator agreed with a buyer on the phone and the figure a later
 * recalculation arrives at have no business being two different numbers.
 */
class DisputeResolved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public OrderDispute $dispute,
        public DisputeResolution $resolution,
        public Money $refundAmount,
        public ?int $resolvedBy = null,
    ) {}
}
