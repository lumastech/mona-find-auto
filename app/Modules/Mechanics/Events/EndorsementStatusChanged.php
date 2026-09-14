<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Events;

use App\Models\User;
use App\Modules\Mechanics\Enums\EndorsementStatus;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A shop answered, or took an endorsement back.
 *
 * One event for endorse, decline and revoke rather than three, because every
 * listener wants the same thing — the row and both ends of the move — and the
 * difference between the three is `to`.
 */
class EndorsementStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public MechanicEndorsement $endorsement,
        public EndorsementStatus $from,
        public EndorsementStatus $to,
        public ?string $reason = null,
        public ?User $actor = null,
    ) {}

    /**
     * Whether a badge went up or came down as a result.
     */
    public function changedBadge(): bool
    {
        return $this->from->isActive() !== $this->to->isActive();
    }
}
