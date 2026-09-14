<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Services;

use App\Models\User;
use App\Modules\Mechanics\Enums\MechanicStatus;
use App\Modules\Mechanics\Exceptions\InvalidMechanicTransition;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicSpeciality;
use Illuminate\Support\Facades\DB;

/**
 * Writing a mechanic's profile: the sign-up form and every later edit.
 *
 * A profile is attached to an account that already exists. There is no
 * separate mechanic registration, because a mechanic is a buyer who also
 * fixes cars — they have bought parts here, they have a verified phone
 * number, and asking them to sign up twice would throw all of that away.
 *
 * Editing stops the moment the profile is sent for approval and reopens if it
 * is rejected. A reviewer has to be looking at the same qualification the
 * applicant submitted, and there is no version history here to fall back on
 * if the text can change underneath them.
 *
 * Specialities are replaced wholesale rather than diffed. Only ids from the
 * active controlled list survive, so a request that posts an arbitrary id, or
 * one for a speciality staff have retired, silently drops it instead of
 * writing a claim nobody can filter on.
 */
class MechanicProfileService
{
    /**
     * Start a profile, or write to the draft that already exists.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>  $specialityIds
     * @param  array<int, array<string, mixed>>  $workHistory
     * @param  array<int, array<string, mixed>>  $references
     *
     * @throws InvalidMechanicTransition when the profile has been sent and is not editable
     */
    public function save(
        User $user,
        array $attributes,
        array $specialityIds = [],
        array $workHistory = [],
        array $references = [],
    ): MechanicProfile {
        $profile = $this->draftFor($user);

        if (! $profile->exists) {
            $profile->user_id = $user->getKey();
        } elseif (! $profile->isEditable()) {
            throw InvalidMechanicTransition::notEditable($profile->status);
        }

        $before = $profile->exists ? $this->auditableState($profile) : null;

        DB::transaction(function () use ($profile, $attributes, $specialityIds, $workHistory, $references): void {
            $profile->fill($attributes);

            /*
             * A rejected profile being edited is on its way back to the
             * queue; clearing the reason here means the applicant is not
             * still reading last month's refusal while they retype.
             */
            if ($profile->status === MechanicStatus::Rejected) {
                $profile->rejection_reason = null;
            }

            $profile->save();

            $profile->specialities()->sync($this->allowedSpecialityIds($specialityIds));

            $this->replaceWorkHistory($profile, $workHistory);
            $this->replaceReferences($profile, $references);
        });

        $profile->refresh()->load(['specialities', 'workHistory', 'references']);

        audit($user, 'mechanic.profile.saved', $profile, $before, $this->auditableState($profile));

        return $profile;
    }

    /**
     * The profile this account is working on: the existing one, or an unsaved
     * new one so a controller always has something to render a form from.
     */
    public function draftFor(User $user): MechanicProfile
    {
        return MechanicProfile::query()
            ->with(['specialities', 'workHistory', 'references'])
            ->firstWhere('user_id', $user->getKey())
            ?? new MechanicProfile([
                'display_name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'province_id' => $user->province_id,
                'city_id' => $user->city_id,
                'status' => MechanicStatus::Draft,
            ]);
    }

    /**
     * Only ids that exist and are still on offer.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function allowedSpecialityIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return MechanicSpeciality::query()
            ->active()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function replaceWorkHistory(MechanicProfile $profile, array $rows): void
    {
        $profile->workHistory()->delete();

        foreach (array_values($rows) as $position => $row) {
            $profile->workHistory()->create([
                'employer' => $row['employer'],
                'role' => $row['role'],
                'description' => $row['description'] ?? null,
                'started_on' => $row['started_on'] ?? null,
                /* A job that has not ended has no end date, whatever was posted. */
                'ended_on' => ($row['is_current'] ?? false) ? null : ($row['ended_on'] ?? null),
                'is_current' => (bool) ($row['is_current'] ?? false),
                'position' => $position,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function replaceReferences(MechanicProfile $profile, array $rows): void
    {
        $profile->references()->delete();

        foreach (array_values($rows) as $position => $row) {
            $profile->references()->create([
                'name' => $row['name'],
                'relationship' => $row['relationship'] ?? null,
                'phone' => $row['phone'],
                'email' => $row['email'] ?? null,
                'note' => $row['note'] ?? null,
                'position' => $position,
            ]);
        }
    }

    /**
     * What the audit trail records about an edit. The claims a reviewer will
     * check, not every column.
     *
     * @return array<string, mixed>
     */
    private function auditableState(MechanicProfile $profile): array
    {
        return [
            'display_name' => $profile->display_name,
            'qualification' => $profile->qualification,
            'qualification_institution' => $profile->qualification_institution,
            'qualification_year' => $profile->qualification_year,
            'years_experience' => $profile->years_experience,
            'city_id' => $profile->city_id,
            'province_id' => $profile->province_id,
            'specialities' => $profile->specialities->pluck('slug')->all(),
            'work_history_count' => $profile->workHistory->count(),
            'reference_count' => $profile->references->count(),
        ];
    }
}
