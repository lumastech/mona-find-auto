<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Events;

use App\Models\User;
use App\Modules\Mechanics\Enums\MechanicStatus;
use App\Modules\Mechanics\Models\MechanicProfile;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A mechanic's profile moved through the approval workflow.
 *
 * Carries both ends of the move, because a listener that only knew the new
 * status could not tell an approval from a reinstatement.
 */
class MechanicApprovalChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public MechanicProfile $profile,
        public MechanicStatus $from,
        public MechanicStatus $to,
        public ?string $reason = null,
        public ?User $actor = null,
    ) {}

    /**
     * Whether this move put the profile in front of the public for the first
     * time, or the first time since it was taken down.
     */
    public function becamePublic(): bool
    {
        return ! $this->from->isPubliclyVisible() && $this->to->isPubliclyVisible();
    }
}
