<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Services;

use App\Models\User;
use App\Modules\Mechanics\Enums\MechanicStatus;
use App\Modules\Mechanics\Events\MechanicApprovalChanged;
use App\Modules\Mechanics\Events\MechanicProfileSubmitted;
use App\Modules\Mechanics\Exceptions\InvalidMechanicTransition;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Notifications\MechanicApprovalDecided;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\DB;

/**
 * The approval workflow: submitted → under review → approved or rejected.
 *
 * Every move is checked against the transitions declared on MechanicStatus,
 * writes an audit row, and announces itself. There is no other way to change
 * a profile's status, which is what makes "an unapproved profile is invisible
 * everywhere" a property of the system rather than a habit.
 *
 * The mechanic role is granted at approval, not at submission — the opposite
 * of a seller, and deliberately. A seller needs the portal while their
 * application is reviewed, because policies, payout details and documents all
 * have to be manageable in the meantime. A mechanic has nothing to manage
 * until they are approved: the role is what lets them ask shops for
 * endorsements, and asking to be endorsed before MonaFind has checked your
 * qualification is the wrong way round.
 */
class MechanicApprovalService
{
    /**
     * The mechanic sends their profile in.
     *
     * @throws InvalidMechanicTransition when there is not enough to review
     */
    public function submit(MechanicProfile $profile, ?User $actor = null): MechanicProfile
    {
        if (! $profile->isReadyToSubmit()) {
            throw InvalidMechanicTransition::incomplete();
        }

        $profile = $this->transitionTo($profile, MechanicStatus::Submitted, $actor, [
            'submitted_at' => now(),
            'rejection_reason' => null,
        ]);

        MechanicProfileSubmitted::dispatch($profile);

        return $profile;
    }

    /**
     * A moderator picks the application up.
     */
    public function beginReview(MechanicProfile $profile, User $actor, ?string $note = null): MechanicProfile
    {
        return $this->transitionTo($profile, MechanicStatus::UnderReview, $actor, [], $note);
    }

    /**
     * Publish the profile and grant the mechanic role.
     */
    public function approve(MechanicProfile $profile, User $actor, ?string $note = null): MechanicProfile
    {
        $profile = $this->transitionTo(
            $profile,
            MechanicStatus::Approved,
            $actor,
            [
                'approved_at' => now(),
                'approved_by' => $actor->getKey(),
                'rejection_reason' => null,
            ],
            $note,
        );

        $profile->user->assignRole(Role::Mechanic->value);

        $profile->user->notify(new MechanicApprovalDecided($profile, MechanicStatus::Approved));

        return $profile;
    }

    /**
     * Turn the application down. The reason is shown to the applicant, so it
     * has to say what to fix rather than only that something is wrong.
     */
    public function reject(MechanicProfile $profile, User $actor, string $reason, ?string $note = null): MechanicProfile
    {
        $profile = $this->transitionTo(
            $profile,
            MechanicStatus::Rejected,
            $actor,
            ['rejection_reason' => $reason, 'approved_at' => null, 'approved_by' => null],
            $note,
            $reason,
        );

        $profile->user->notify(new MechanicApprovalDecided($profile, MechanicStatus::Rejected, $reason));

        return $profile;
    }

    /**
     * Take an approved profile down.
     *
     * The role goes with it. A suspended mechanic must not be able to ask
     * shops for endorsements while they are off the directory, and the
     * endorsements they already hold stop showing because nothing about the
     * profile is public any more — the rows themselves are left alone, so
     * reinstating restores the badges rather than asking every shop again.
     */
    public function suspend(MechanicProfile $profile, User $actor, string $reason): MechanicProfile
    {
        $profile = $this->transitionTo($profile, MechanicStatus::Suspended, $actor, [], null, $reason);

        $profile->user->removeRole(Role::Mechanic->value);

        $profile->user->notify(new MechanicApprovalDecided($profile, MechanicStatus::Suspended, $reason));

        return $profile;
    }

    /**
     * Put a suspended profile back.
     */
    public function reinstate(MechanicProfile $profile, User $actor, string $reason): MechanicProfile
    {
        $profile = $this->transitionTo(
            $profile,
            MechanicStatus::Approved,
            $actor,
            ['approved_at' => now(), 'approved_by' => $actor->getKey()],
            null,
            $reason,
        );

        $profile->user->assignRole(Role::Mechanic->value);

        return $profile;
    }

    /**
     * Apply a move: check it is legal, persist it, audit it, announce it.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidMechanicTransition
     */
    private function transitionTo(
        MechanicProfile $profile,
        MechanicStatus $to,
        ?User $actor,
        array $attributes = [],
        ?string $note = null,
        ?string $reason = null,
    ): MechanicProfile {
        $from = $profile->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidMechanicTransition::between($from, $to);
        }

        DB::transaction(function () use ($profile, $to, $attributes, $note): void {
            $profile->forceFill([
                ...$attributes,
                'status' => $to,
                'review_note' => $note ?? $profile->review_note,
            ])->save();
        });

        audit(
            $actor,
            'mechanic.'.$to->value,
            $profile,
            ['status' => $from->value],
            ['status' => $to->value],
            $reason,
            ['note' => $note],
        );

        MechanicApprovalChanged::dispatch($profile, $from, $to, $reason, $actor);

        return $profile->refresh();
    }
}
