<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Mechanics\Enums\MechanicStatus;
use App\Modules\Mechanics\Http\Resources\MechanicEndorsementResource;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicReference;
use App\Modules\Mechanics\Models\MechanicWorkHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The staff approval queue, and one application in full.
 *
 * The list defaults to the queue rather than to everybody: the screen exists
 * to clear applications, and a reviewer opening it wants the oldest thing
 * still waiting, not page one of every mechanic on the platform.
 *
 * The detail screen is the only surface anywhere that reads the references
 * and the certificates. Both are loaded here and nowhere else — see
 * MechanicProfileResource, which has no branch that could put them on a
 * public page.
 */
class MechanicController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(Request $request): Response
    {
        Gate::forUser($this->currentUser($request))->authorize('viewAny', MechanicProfile::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(MechanicStatus::cases(), 'value'))],
        ]);

        $status = isset($filters['status']) ? MechanicStatus::from($filters['status']) : null;

        $mechanics = MechanicProfile::query()
            ->with(['user', 'province', 'city', 'specialities'])
            ->withCount(['activeEndorsements as endorsement_count'])
            ->search($filters['search'] ?? null)
            ->when(
                $status !== null,
                fn (Builder $query) => $query->where('status', $status)->latest('submitted_at'),
                /* No filter means the queue: what still needs a decision, oldest first. */
                fn (Builder $query) => $query->inApprovalQueue(),
            )
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MechanicProfile $profile): array => [
                'id' => $profile->getKey(),
                'slug' => $profile->slug,
                'display_name' => $profile->display_name,
                'account' => $profile->user->email,
                'qualification' => $profile->qualification,
                'years_experience' => $profile->years_experience,
                'locality' => $profile->locality(),
                'specialities' => $profile->specialities->pluck('name')->all(),
                'endorsement_count' => (int) $profile->getAttribute('endorsement_count'),
                'status' => $profile->status->value,
                'status_label' => $profile->status->label(),
                'status_variant' => $profile->status->badgeVariant(),
                'submitted_at' => $profile->submitted_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/mechanics/Index', [
            'mechanics' => $mechanics,
            'filters' => [
                'search' => $filters['search'] ?? null,
                'status' => $status?->value,
            ],
            'statuses' => MechanicStatus::options(),
            'queueCount' => MechanicProfile::query()->inApprovalQueue()->count(),
        ]);
    }

    public function show(Request $request, MechanicProfile $mechanic): Response
    {
        Gate::forUser($this->currentUser($request))->authorize('view', $mechanic);

        $mechanic->load([
            'user',
            'province',
            'city',
            'specialities',
            'workHistory',
            /* Staff-only, both of them. */
            'references',
            'approver',
            'media',
        ]);

        return Inertia::render('admin/mechanics/Show', [
            'mechanic' => [
                'id' => $mechanic->getKey(),
                'slug' => $mechanic->slug,
                'display_name' => $mechanic->display_name,
                'headline' => $mechanic->headline,
                'bio' => $mechanic->bio,
                'qualification' => $mechanic->qualification,
                'qualification_institution' => $mechanic->qualification_institution,
                'qualification_year' => $mechanic->qualification_year,
                'years_experience' => $mechanic->years_experience,
                'locality' => $mechanic->locality(),
                'street' => $mechanic->street,
                'plot_number' => $mechanic->plot_number,
                /* Staff read the real values: they are here to ring them. */
                'phone' => $mechanic->phone,
                'email' => $mechanic->email,
                'is_mobile' => $mechanic->is_mobile,
                'accepting_work' => $mechanic->accepting_work,
                'avatar_url' => $mechanic->getFirstMediaUrl(MechanicProfile::AVATAR_COLLECTION) ?: null,
                'specialities' => $mechanic->specialities->pluck('name')->all(),
                'work_history' => $mechanic->workHistory
                    ->map(static fn (MechanicWorkHistory $job): array => [
                        'employer' => $job->employer,
                        'role' => $job->role,
                        'description' => $job->description,
                        'period' => $job->period(),
                    ])->all(),
                'references' => $mechanic->references
                    ->map(static fn (MechanicReference $reference): array => [
                        'name' => $reference->name,
                        'relationship' => $reference->relationship,
                        'phone' => $reference->phone,
                        'email' => $reference->email,
                        'note' => $reference->note,
                    ])->all(),
                'certificates' => $mechanic->getMedia(MechanicProfile::CERTIFICATES_COLLECTION)
                    ->map(static fn (Media $media): array => [
                        'id' => $media->getKey(),
                        'name' => $media->name,
                        'size' => $media->size,
                    ])->all(),
                'account' => [
                    'id' => $mechanic->user->getKey(),
                    'name' => $mechanic->user->name,
                    'email' => $mechanic->user->email,
                    'phone' => $mechanic->user->phone,
                ],
                'status' => $mechanic->status->value,
                'status_label' => $mechanic->status->label(),
                'status_variant' => $mechanic->status->badgeVariant(),
                'allowed_transitions' => array_map(
                    static fn (MechanicStatus $to): array => ['value' => $to->value, 'label' => $to->label()],
                    $mechanic->status->allowedTransitions(),
                ),
                'review_note' => $mechanic->review_note,
                'rejection_reason' => $mechanic->rejection_reason,
                'submitted_at' => $mechanic->submitted_at?->toIso8601String(),
                'approved_at' => $mechanic->approved_at?->toIso8601String(),
                'approved_by' => $mechanic->approver?->name,
                'public_url' => $mechanic->isPubliclyVisible() ? route('mechanics.show', $mechanic) : null,
            ],
            'endorsements' => MechanicEndorsement::query()
                ->where('mechanic_profile_id', $mechanic->getKey())
                ->with(['profile', 'seller'])
                ->latest('id')
                ->get()
                ->map(fn (MechanicEndorsement $endorsement): array => MechanicEndorsementResource::make($endorsement)->resolve($request))
                ->all(),
        ]);
    }
}
