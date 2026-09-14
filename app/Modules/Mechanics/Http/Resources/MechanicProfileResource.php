<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Resources;

use App\Models\User;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicSpeciality;
use App\Modules\Mechanics\Models\MechanicWorkHistory;
use App\Modules\Mechanics\Support\MechanicContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A mechanic's public profile.
 *
 * The directory card, the profile page and /api/v1/mechanics all answer
 * through this, so the contact-blur rule is applied once: a guest gets labels
 * and a masked shape, a logged-in buyer gets the real values.
 *
 * Two badges, carried separately because they mean different things.
 * `approved` is MonaFind's: staff checked the qualification. `endorsements`
 * is a list of shops that have vouched for this person, one badge each, and a
 * mechanic may hold any number. Neither implies the other, so the page can
 * render one without the other and a buyer can tell them apart.
 *
 * References and certificates never appear here. They are not merely
 * withheld from guests — nothing in this resource can put them on a page at
 * all, which is why there is no `whenLoaded('references')` branch to get
 * wrong later.
 *
 * @mixin MechanicProfile
 */
class MechanicProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MechanicProfile $profile */
        $profile = $this->resource;

        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;

        return [
            'id' => $profile->getKey(),
            'slug' => $profile->slug,
            'display_name' => $profile->display_name,
            'headline' => $profile->headline,
            'bio' => $profile->bio,

            'qualification' => $profile->qualification,
            'qualification_institution' => $profile->qualification_institution,
            'qualification_year' => $profile->qualification_year,
            'years_experience' => $profile->years_experience,

            /* The staff badge. */
            'approved' => $profile->isApproved(),
            'approved_at' => $profile->approved_at?->toIso8601String(),

            'is_mobile' => $profile->is_mobile,
            'accepting_work' => $profile->accepting_work,

            'location' => [
                'province' => $profile->province->name,
                'city' => $profile->city->name,
                'locality' => $profile->locality(),
                'latitude' => $profile->latitude,
                'longitude' => $profile->longitude,
            ],

            /*
             * Never the real values for a guest — the masking is server-side,
             * because a CSS blur over a real number is in the response and
             * anybody can read the page source.
             */
            'contact' => MechanicContact::for($profile, $viewer)->toArray(),

            'specialities' => $profile->relationLoaded('specialities')
                ? $profile->specialities
                    ->map(static fn (MechanicSpeciality $speciality): array => $speciality->toOption())
                    ->all()
                : [],

            /* The shop badges: one per live endorsement, each named. */
            'endorsements' => $profile->relationLoaded('activeEndorsements')
                ? $profile->activeEndorsements
                    ->map(static fn (MechanicEndorsement $endorsement): array => [
                        'id' => $endorsement->getKey(),
                        'label' => $endorsement->badgeLabel(),
                        'seller' => [
                            'slug' => $endorsement->seller->slug,
                            'business_name' => $endorsement->seller->business_name,
                            'verified' => $endorsement->seller->isVerified(),
                        ],
                        'endorsed_at' => $endorsement->endorsed_at?->toIso8601String(),
                    ])
                    ->all()
                : [],

            'work_history' => $profile->relationLoaded('workHistory')
                ? $profile->workHistory
                    ->map(static fn (MechanicWorkHistory $job): array => [
                        'employer' => $job->employer,
                        'role' => $job->role,
                        'description' => $job->description,
                        'period' => $job->period(),
                        'is_current' => $job->is_current,
                    ])
                    ->all()
                : [],

            'avatar_url' => $profile->getFirstMediaUrl(MechanicProfile::AVATAR_COLLECTION) ?: null,

            /*
             * Attached by MechanicDirectory's subquery, so a card and the
             * profile header show the same numbers. Absent means "not asked
             * for", which is why it is not faked with a zero.
             */
            'rating' => [
                'average' => $profile->getAttribute('rating_average') === null
                    ? null
                    : round((float) $profile->getAttribute('rating_average'), 2),
                'count' => (int) ($profile->getAttribute('rating_count') ?? 0),
            ],

            /*
             * Messaging is a later module. The flag is here now so the page
             * has one thing to branch on rather than a route-existence check
             * scattered through a template.
             */
            'can' => [
                'message' => $viewer !== null && Gate::forUser($viewer)->allows('view', $profile),
            ],

            'member_since' => $profile->created_at?->toIso8601String(),
        ];
    }
}
