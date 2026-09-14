<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Policies;

use App\Models\User;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Support\Roles\Role;

/**
 * Who may read and act on a mechanic's profile.
 *
 * `view` is the rule that matters. An approved profile is public and needs no
 * account at all; anything else is visible only to the person whose profile
 * it is and to staff. That is enforced a second time in the query — see
 * MechanicProfile::scopePubliclyVisible() — because a policy protects a page
 * and a scope protects a list, and an unapproved profile appearing in a
 * directory is the failure that matters.
 */
class MechanicProfilePolicy
{
    /**
     * The abilities before() must NOT wave through.
     *
     * @var array<int, string>
     */
    private const DECISIONS = ['approve'];

    /**
     * Staff see every application: approving them is not possible otherwise.
     *
     * Reading only, though. Deciding falls through to approve(), which is
     * narrower — a finance staffer has every reason to open a mechanic's file
     * while chasing a payment and no business putting MonaFind's badge on it.
     * Catalog and Sellers draw the same line for their own decisions.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, self::DECISIONS, true)) {
            return null;
        }

        return $user->hasAnyRole(Role::staffConsole()) ? true : null;
    }

    /**
     * The staff queue.
     */
    public function viewAny(User $user): bool
    {
        /* Reached only for non-staff; before() lets staff through. */
        return false;
    }

    public function view(?User $user, MechanicProfile $profile): bool
    {
        if ($profile->isPubliclyVisible()) {
            return true;
        }

        return $user !== null && $profile->belongsToUser($user);
    }

    /**
     * Edit the profile. Its owner, and only while it is theirs to edit —
     * a reviewer has to read what was submitted, not what it became.
     *
     * Staff are let through by before() for reading, but nobody writes a
     * mechanic's qualification for them: the admin console has no edit form,
     * which is where that is actually enforced.
     */
    public function update(User $user, MechanicProfile $profile): bool
    {
        return $profile->belongsToUser($user) && $profile->isEditable();
    }

    /**
     * Send it for approval.
     */
    public function submit(User $user, MechanicProfile $profile): bool
    {
        return $profile->belongsToUser($user) && $profile->isEditable();
    }

    /**
     * Ask a shop for an endorsement.
     *
     * Approved profiles only. Asking a shop to vouch for a qualification
     * MonaFind has not checked puts the shop in the position of doing the
     * checking, which is not what the badge means.
     */
    public function requestEndorsement(User $user, MechanicProfile $profile): bool
    {
        return $profile->belongsToUser($user) && $profile->isApproved();
    }

    /**
     * Read the certificates and the references.
     *
     * Staff only, by before(). These are scans of somebody's papers and a
     * third party's phone number, and the reviewer is the only person with a
     * reason to open them.
     */
    public function viewPrivateDetails(User $user, MechanicProfile $profile): bool
    {
        return $profile->belongsToUser($user);
    }

    /**
     * Move the application through the workflow.
     *
     * Approving a profile publishes somebody's qualification under MonaFind's
     * name, so it is moderation work rather than staff work in general.
     * Exempted from before() above; this is the whole check.
     */
    public function approve(User $user, MechanicProfile $profile): bool
    {
        return $user->hasAnyRole([Role::Moderator->value, Role::PlatformAdmin->value]);
    }
}
