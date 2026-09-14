<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Events;

use App\Modules\Mechanics\Models\MechanicProfile;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A mechanic has sent their profile in for approval.
 *
 * What the staff queue is fed by, and where a notification to moderators
 * would hang. Nothing that has to be true depends on a listener running: the
 * queue is a query over `status`, not a table something writes into.
 */
class MechanicProfileSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public MechanicProfile $profile) {}
}
