<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Http\Resources\PayoutAccountResource;
use App\Modules\Sellers\Http\Resources\SellerPolicyResource;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerVerificationEvent;
use App\Modules\Sellers\Services\SellerDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff console's view of the platform's sellers.
 */
class SellerController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly SellerDocumentService $documents) {}

    /**
     * The seller list, which doubles as the verification queue.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Seller::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(VerificationStatus::cases(), 'value'))],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_column(SellerType::cases(), 'value'))],
            'queue' => ['nullable', 'boolean'],
        ]);

        $sellers = Seller::query()
            ->with(['province:id,name', 'city:id,name', 'user:id,name,email'])
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('verification_status', $status))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            /* The queue view is the working order: oldest application first. */
            ->when($filters['queue'] ?? null, fn ($query) => $query->inVerificationQueue(), fn ($query) => $query->latest('id'))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Seller $seller): array => $this->summarise($seller));

        return Inertia::render('admin/sellers/Index', [
            'sellers' => $sellers,
            'filters' => $filters,
            'statuses' => VerificationStatus::options(),
            'types' => SellerType::options(),
            'queueCount' => Seller::query()->inVerificationQueue()->count(),
        ]);
    }

    /**
     * One seller's file: the application, the documents, the decisions taken
     * so far, and what is blocking a badge.
     */
    public function show(Request $request, Seller $seller): Response
    {
        Gate::authorize('view', $seller);

        $seller->load(['province', 'city', 'user', 'verifier']);
        $actor = $this->currentUser($request);
        $status = $seller->verification_status;

        return Inertia::render('admin/sellers/Show', [
            'seller' => [
                ...$this->summarise($seller),
                'description' => $seller->description,
                'street' => $seller->street,
                'plot_number' => $seller->plot_number,
                'latitude' => $seller->latitude,
                'longitude' => $seller->longitude,
                'phone' => $seller->phone,
                'email' => $seller->email,
                'contact_person' => $seller->contact_person,
                'bay_count' => $seller->bay_count,
                'opening_hours' => $seller->opening_hours,
                'payment_mode' => $seller->payment_mode->value,
                'payment_mode_label' => $seller->payment_mode->label(),
                'monetisation_policy_id' => $seller->monetisation_policy_id,
                'verification_note' => $seller->verification_note,
                'rejection_reason' => $seller->rejection_reason,
                'inspection_scheduled_for' => $seller->inspection_scheduled_for?->toIso8601String(),
                'verified_by' => $seller->verifier?->name,
                'owner' => [
                    'id' => $seller->user->id,
                    'name' => $seller->user->name,
                    'email' => $seller->user->email,
                ],
            ],
            'documents' => $this->documents->summarise($seller),
            'policies' => SellerPolicyResource::collection($seller->policies()->get())->resolve(),
            'policyTypes' => PolicyType::options(),
            'payoutAccounts' => PayoutAccountResource::collection($seller->payoutAccounts()->get())->resolve(),
            'history' => $this->historyFor($seller),
            'activity' => $this->activityFor($seller),
            'checklist' => $this->checklistFor($seller),
            /* What a reviewer may do right now, so the buttons match the workflow. */
            'transitions' => array_map(
                static fn (VerificationStatus $to): array => ['value' => $to->value, 'label' => $to->label()],
                $status->allowedTransitions(),
            ),
            'blockedFromVerifying' => ! $seller->mayBeVerified(),
            'can' => [
                'verify' => $actor->can('verify', $seller),
                'suspend' => $actor->can('suspend', $seller),
                'viewDocuments' => $actor->can('viewDocuments', $seller),
                'setCommercialTerms' => $actor->can('setCommercialTerms', $seller),
            ],
        ]);
    }

    /**
     * The checks a reviewer ticks off, and which of them the system can
     * already answer for them.
     *
     * @return array<int, array{key: string, label: string, satisfied: bool, automatic: bool}>
     */
    private function checklistFor(Seller $seller): array
    {
        return [
            [
                'key' => 'registration_number',
                'label' => 'Registration number present and matches PACRA',
                'satisfied' => $seller->mayBeVerified(),
                'automatic' => false,
            ],
            [
                'key' => 'documents',
                'label' => 'All required documents uploaded and legible',
                'satisfied' => $seller->missingDocuments() === [],
                'automatic' => false,
            ],
            [
                'key' => 'policies',
                'label' => 'Delivery, refund and warranty policies published',
                'satisfied' => $seller->missingPolicies() === [],
                'automatic' => true,
            ],
            [
                'key' => 'payout_account',
                'label' => 'Payout account confirmed with the bank or wallet provider',
                'satisfied' => $seller->payoutAccounts()->whereNotNull('lenco_recipient_id')->exists(),
                'automatic' => true,
            ],
            [
                'key' => 'premises',
                'label' => 'Premises seen, or a good reason not to',
                'satisfied' => false,
                'automatic' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function historyFor(Seller $seller): array
    {
        return $seller->verificationEvents()->with('actor:id,name')->get()
            ->map(static function (SellerVerificationEvent $event): array {
                $actor = $event->actor;

                return [
                    'id' => $event->id,
                    'summary' => $event->summary(),
                    'from' => $event->from_status?->value,
                    'to' => $event->to_status->value,
                    'note' => $event->note,
                    'reason' => $event->reason,
                    'checklist' => $event->checklist,
                    /* Null when the seller themselves moved it, e.g. by submitting. */
                    'actor' => $actor === null ? 'System' : $actor->name,
                    'created_at' => $event->created_at->toIso8601String(),
                ];
            })->all();
    }

    /**
     * Everything the permanent audit trail holds about this business.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityFor(Seller $seller): array
    {
        return AuditLog::query()
            ->where('subject_type', $seller->getMorphClass())
            ->where('subject_id', $seller->getKey())
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor_label,
                'reason' => $log->reason,
                'before' => $log->before,
                'after' => $log->after,
                'created_at' => $log->created_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Seller $seller): array
    {
        return [
            'id' => $seller->id,
            'slug' => $seller->slug,
            'business_name' => $seller->business_name,
            'type' => $seller->type->value,
            'type_label' => $seller->type->label(),
            'registration_number' => $seller->registration_number,
            'status' => $seller->verification_status->value,
            'status_label' => $seller->verification_status->label(),
            'verified' => $seller->isVerified(),
            'location' => $seller->singleLine(),
            'province' => $seller->province->name,
            'city' => $seller->city->name,
            'owner_name' => $seller->user->name,
            'submitted_at' => $seller->submitted_at?->toIso8601String(),
            'verified_at' => $seller->verified_at?->toIso8601String(),
            'created_at' => $seller->created_at?->toIso8601String(),
        ];
    }
}
