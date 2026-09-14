<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\LocationDirectory;
use App\Modules\Mechanics\Exceptions\InvalidMechanicTransition;
use App\Modules\Mechanics\Http\Requests\Storefront\MechanicApplicationRequest;
use App\Modules\Mechanics\Http\Resources\MechanicEndorsementResource;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Services\MechanicApprovalService;
use App\Modules\Mechanics\Services\MechanicDirectory;
use App\Modules\Mechanics\Services\MechanicProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Applying to be a listed mechanic, and watching that application.
 *
 * Behind auth, because a mechanic profile attaches to an account that already
 * exists: somebody who has bought parts here, whose phone is already
 * verified. There is no separate mechanic sign-up to keep in step with the
 * buyer one.
 *
 * This is also where an approved mechanic lives afterwards — their status,
 * their endorsements, and the form to ask another shop — so there is one
 * page to bookmark rather than a wizard that disappears once it is finished.
 */
class MechanicApplicationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly MechanicProfileService $profiles,
        private readonly MechanicApprovalService $approval,
        private readonly MechanicDirectory $directory,
        private readonly LocationDirectory $locations,
    ) {}

    public function show(Request $request): Response
    {
        $user = $this->currentUser($request);
        $profile = $this->profiles->draftFor($user);

        return Inertia::render('storefront/mechanics/Apply', [
            'profile' => $this->formState($profile),
            'status' => [
                'value' => $profile->status->value,
                'label' => $profile->status->label(),
                'variant' => $profile->status->badgeVariant(),
                'guidance' => $profile->status->applicantGuidance(),
                'editable' => $profile->isEditable(),
                'submitted_at' => $profile->submitted_at?->toIso8601String(),
                'rejection_reason' => $profile->rejection_reason,
                'public_url' => $profile->exists && $profile->isPubliclyVisible()
                    ? route('mechanics.show', $profile)
                    : null,
            ],
            'specialities' => $this->directory->specialityOptions(),
            'provinces' => $this->locations->provincesWithCities(),
            'endorsements' => $this->endorsementsFor($profile, $request),
            'canRequestEndorsements' => $profile->exists
                && Gate::forUser($user)->allows('requestEndorsement', $profile),
        ]);
    }

    /**
     * Save the form. Creates the profile the first time.
     */
    public function store(MechanicApplicationRequest $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $existing = $this->profiles->draftFor($user);

        if ($existing->exists) {
            Gate::forUser($user)->authorize('update', $existing);
        }

        try {
            $this->profiles->save(
                $user,
                $request->profileAttributes(),
                $request->specialityIds(),
                $request->workHistory(),
                $request->references(),
            );
        } catch (InvalidMechanicTransition $exception) {
            throw ValidationException::withMessages(['profile' => $exception->getMessage()]);
        }

        return to_route('mechanics.apply')->with('toast', [
            'type' => 'success',
            'message' => __('Your profile has been saved.'),
        ]);
    }

    /**
     * Send it to MonaFind.
     */
    public function submit(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $profile = $this->profiles->draftFor($user);

        abort_unless($profile->exists, 404);
        Gate::forUser($user)->authorize('submit', $profile);

        try {
            $this->approval->submit($profile, $user);
        } catch (InvalidMechanicTransition $exception) {
            throw ValidationException::withMessages(['profile' => $exception->getMessage()]);
        }

        return to_route('mechanics.apply')->with('toast', [
            'type' => 'success',
            'message' => __('Your profile is with MonaFind. We will let you know as soon as it has been reviewed.'),
        ]);
    }

    /**
     * The saved answers, shaped as the form expects them back.
     *
     * @return array<string, mixed>
     */
    private function formState(MechanicProfile $profile): array
    {
        return [
            'exists' => $profile->exists,
            'display_name' => $profile->display_name,
            'headline' => $profile->headline,
            'bio' => $profile->bio,
            'qualification' => $profile->qualification,
            'qualification_institution' => $profile->qualification_institution,
            'qualification_year' => $profile->qualification_year,
            'years_experience' => $profile->years_experience ?? 0,
            'province_id' => $profile->province_id,
            'city_id' => $profile->city_id,
            'street' => $profile->street,
            'plot_number' => $profile->plot_number,
            'phone' => $profile->phone,
            'email' => $profile->email,
            'is_mobile' => (bool) $profile->is_mobile,
            'accepting_work' => $profile->exists ? (bool) $profile->accepting_work : true,
            'speciality_ids' => $profile->exists ? $profile->specialities->pluck('id')->all() : [],
            'work_history' => $profile->exists
                ? $profile->workHistory->map(static fn ($job): array => [
                    'employer' => $job->employer,
                    'role' => $job->role,
                    'description' => $job->description,
                    'started_on' => $job->started_on?->toDateString(),
                    'ended_on' => $job->ended_on?->toDateString(),
                    'is_current' => $job->is_current,
                ])->all()
                : [],
            /*
             * References come back to their own author and to nobody else.
             * Every other surface that renders a mechanic goes through
             * MechanicProfileResource, which has no branch for them at all.
             */
            'references' => $profile->exists
                ? $profile->references->map(static fn ($reference): array => [
                    'name' => $reference->name,
                    'relationship' => $reference->relationship,
                    'phone' => $reference->phone,
                    'email' => $reference->email,
                    'note' => $reference->note,
                ])->all()
                : [],
        ];
    }

    /**
     * Every endorsement this mechanic has asked for, answered or not.
     *
     * @return array<int, array<string, mixed>>
     */
    private function endorsementsFor(MechanicProfile $profile, Request $request): array
    {
        if (! $profile->exists) {
            return [];
        }

        return MechanicEndorsement::query()
            ->where('mechanic_profile_id', $profile->getKey())
            ->with(['profile', 'seller'])
            ->latest('id')
            ->get()
            ->map(fn (MechanicEndorsement $endorsement): array => MechanicEndorsementResource::make($endorsement)->resolve($request))
            ->all();
    }
}
