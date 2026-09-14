<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Events;

use App\Models\User;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A seller's application moved through the verification workflow.
 *
 * Other modules listen for this rather than polling the column: Search
 * reindexes on the verified weighting, Catalog hides a suspended seller's
 * listings, Messaging tells the seller what happened.
 */
class SellerVerificationChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Seller $seller,
        public readonly VerificationStatus $from,
        public readonly VerificationStatus $to,
        public readonly ?string $reason = null,
        public readonly ?User $actor = null,
    ) {}

    /**
     * Whether this is the move that grants the badge.
     */
    public function grantedBadge(): bool
    {
        return ! $this->from->isVerified() && $this->to->isVerified();
    }

    /**
     * Whether this is the move that takes it away.
     */
    public function revokedBadge(): bool
    {
        return $this->from->isVerified() && ! $this->to->isVerified();
    }
}
