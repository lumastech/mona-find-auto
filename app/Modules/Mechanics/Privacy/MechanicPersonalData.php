<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Privacy;

use App\Models\User;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Privacy\Contracts\PersonalDataSource;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataSection;

/**
 * A mechanic's public profile.
 *
 * Denser in personal data than an ordinary account: a name the public sees, a
 * work history, a phone number, a street, and the contact details of referees
 * who are third parties and never agreed to anything themselves.
 *
 * ## The whole profile is deleted
 *
 * Unlike an order, a mechanic profile is not a record of a transaction. It is
 * a public advertisement, and there is no basis for keeping one for somebody
 * who has left. The work histories, references and endorsement requests go
 * with it — the migration cascades them, but they are deleted explicitly here
 * so the tally reports what actually went.
 *
 * Endorsements a seller granted are cascade-deleted with the profile, which
 * is correct: an endorsement is a statement about a specific mechanic's
 * profile and means nothing once that profile does not exist. The seller's
 * own records are unaffected.
 *
 * Reviews of the mechanic are Ratings' business and are handled there.
 */
class MechanicPersonalData implements PersonalDataSource
{
    public function key(): string
    {
        return 'mechanic_profile';
    }

    /**
     * @return array<int, PersonalDataSection>
     */
    public function export(User $user): array
    {
        $profile = MechanicProfile::query()
            ->where('user_id', $user->getKey())
            ->with(['workHistory', 'references', 'specialities:id,name', 'province:id,name', 'city:id,name'])
            ->first();

        if ($profile === null) {
            return [];
        }

        return [
            PersonalDataSection::single('Your mechanic profile', [
                'Display name' => $profile->display_name,
                'Headline' => $profile->headline,
                'About you' => $profile->bio,
                'Qualification' => $profile->qualification,
                'Institution' => $profile->qualification_institution,
                'Year qualified' => $profile->qualification_year,
                'Years of experience' => $profile->years_experience,
                'Province' => $profile->province->name,
                'City' => $profile->city->name,
                'Street' => $profile->street,
                'Plot number' => $profile->plot_number,
                'Phone on profile' => $profile->phone,
                'Email on profile' => $profile->email,
                'Mobile mechanic' => $profile->is_mobile,
                'Taking work' => $profile->accepting_work,
                'Status' => $profile->status->value,
                'Submitted on' => $profile->submitted_at?->toDateTimeString(),
                'Approved on' => $profile->approved_at?->toDateTimeString(),
            ]),

            PersonalDataSection::make(
                'Your specialities',
                $profile->specialities->map(static fn ($speciality): array => [
                    'Speciality' => $speciality->name,
                ])->all(),
            ),

            PersonalDataSection::make(
                'Your work history',
                $profile->workHistory->map(static fn ($history): array => [
                    'Employer' => $history->employer,
                    'Role' => $history->role,
                    'What you did' => $history->description,
                    'From' => $history->started_on?->toDateString(),
                    'To' => $history->ended_on?->toDateString(),
                    'Current' => $history->is_current,
                ])->all(),
            ),

            PersonalDataSection::make(
                'Your references',
                $profile->references->map(static fn ($reference): array => [
                    'Name' => $reference->name,
                    'Relationship' => $reference->relationship,
                    'Phone' => $reference->phone,
                    'Email' => $reference->email,
                    'Note' => $reference->note,
                ])->all(),
                'People you named as references. Their details were given to us by you, not by them.',
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function erase(User $user, Anonymiser $anonymiser): array
    {
        $profile = MechanicProfile::query()->where('user_id', $user->getKey())->first();

        if ($profile === null) {
            return [];
        }

        /*
         * Counted before the delete, because afterwards there is nothing left
         * to count and the report would say nothing happened.
         */
        $tally = [
            'mechanic_work_histories' => $profile->workHistory()->count(),
            'mechanic_references' => $profile->references()->count(),
            'mechanic_endorsements' => $profile->endorsements()->count(),
            'mechanic_profile_speciality' => $profile->specialities()->count(),
            'mechanic_profiles' => 1,
        ];

        /*
         * Media — a profile photo is a photograph of a person and the most
         * obviously personal thing on the profile. MediaLibrary removes the
         * files with the model, but only when the model is deleted through
         * Eloquent rather than by a mass delete.
         */
        $profile->delete();

        return $tally;
    }
}
