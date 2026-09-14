<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Events;

use App\Modules\Mechanics\Models\MechanicEndorsement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A mechanic has asked a shop to vouch for them.
 *
 * The shop's portal notification hangs off this. The request itself is the
 * row; the notification is a courtesy, so a backed-up queue costs a shop a
 * ping rather than the request.
 */
class EndorsementRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public MechanicEndorsement $endorsement) {}
}
