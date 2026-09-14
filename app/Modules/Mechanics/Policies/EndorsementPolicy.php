<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Policies;

use App\Models\User;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;

/**
 * Who may answer an endorsement request, and who may read one.
 *
 * The rule the badge depends on: only the shop a request was addressed to can
 * answer it. It is checked here and again in EndorsementService, because a
 * policy protects a route and the service protects the rule — an endorsement
 * saying "Endorsed by Kabwata Motors" has to mean Kabwata Motors said so, by
 * whatever path the code was reached.
 *
 * Note that staff are NOT given a way to endorse. MonaFind approves
 * mechanics; it does not vouch for them on a shop's behalf, and a staff
 * override here would quietly turn the second badge into the first.
 */
class EndorsementPolicy
{
    /**
     * Staff read endorsements as part of reviewing a profile. They do not
     * decide them — see `decide`, which is not reachable through before().
     */
    public function view(User $user, MechanicEndorsement $endorsement): bool
    {
        if ($user->hasAnyRole(Role::staffConsole())) {
            return true;
        }

        return $this->isTheMechanic($user, $endorsement) || $this->isTheShop($user, $endorsement);
    }

    /**
     * Endorse or decline. The addressed shop, nobody else.
     */
    public function decide(User $user, MechanicEndorsement $endorsement): bool
    {
        return $this->isTheShop($user, $endorsement) && $endorsement->status->awaitsSeller();
    }

    /**
     * Take an endorsement back. The shop that gave it.
     */
    public function revoke(User $user, MechanicEndorsement $endorsement): bool
    {
        return $this->isTheShop($user, $endorsement) && $endorsement->status->isActive();
    }

    private function isTheShop(User $user, MechanicEndorsement $endorsement): bool
    {
        $seller = $user->seller;

        return $seller instanceof Seller && $endorsement->isAddressedTo($seller);
    }

    private function isTheMechanic(User $user, MechanicEndorsement $endorsement): bool
    {
        return $endorsement->profile->belongsToUser($user);
    }
}
