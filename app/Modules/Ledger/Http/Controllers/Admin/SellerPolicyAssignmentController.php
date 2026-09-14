<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Ledger\Http\Resources\MonetisationPolicyResource;
use App\Modules\Ledger\Models\MonetisationPolicy;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Ledger\Services\MonetisationPolicyService;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which seller is on which terms.
 *
 * A separate screen from the policy manager because it answers a different
 * question. The manager asks what terms exist; this asks who is on them, and
 * that is the list somebody scans when a payout is queried.
 *
 * The payable and reserve balances are shown alongside, because the next
 * thing anybody asks after "what is this shop being charged" is "and what do
 * we owe them".
 */
class SellerPolicyAssignmentController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly MonetisationPolicyService $policies,
        private readonly LedgerBalances $balances,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MonetisationPolicy::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'policy' => ['nullable', 'string', 'max:64'],
        ]);

        $default = MonetisationPolicy::default();
        $filter = $filters['policy'] ?? null;

        /*
         * The assignment is read as a plain column rather than through a
         * relation on Seller. A belongsTo there would make the Sellers module
         * depend on this one, and the dependency only runs the other way:
         * Ledger knows what a seller is, Sellers has never heard of a policy.
         */
        $sellers = Seller::query()
            ->search($filters['search'] ?? null)
            ->when($filter === 'default', fn ($query) => $query->whereNull('monetisation_policy_id'))
            ->when(
                ! in_array($filter, [null, '', 'default'], true),
                fn ($query) => $query->where(
                    'monetisation_policy_id',
                    MonetisationPolicy::query()->where('slug', $filter)->value('id'),
                ),
            )
            ->orderBy('business_name')
            ->paginate(25)
            ->withQueryString();

        /** @var Collection<int, MonetisationPolicy> $assigned */
        $assigned = MonetisationPolicy::query()
            ->whereIn('id', $sellers->pluck('monetisation_policy_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $sellers->through(function (Seller $seller) use ($assigned, $default): array {
            $policy = $assigned->get($seller->monetisation_policy_id);

            return [
                'id' => $seller->id,
                'slug' => $seller->slug,
                'business_name' => $seller->business_name,
                'payment_mode' => $seller->payment_mode->value,
                'payment_mode_label' => $seller->payment_mode->label(),
                'policy_slug' => $policy?->slug,
                'policy_name' => $policy->name ?? $default->name ?? 'Platform settings',
                'on_default' => $policy === null,
                'payable_ngwee' => $this->balances->sellerPayable($seller)->ngwee,
                'reserve_ngwee' => $this->balances->sellerReserve($seller)->ngwee,
            ];
        });

        return Inertia::render('admin/finance/policies/Sellers', [
            'sellers' => $sellers,
            'filters' => [
                'search' => $filters['search'] ?? null,
                'policy' => $filters['policy'] ?? null,
            ],
            'policies' => MonetisationPolicy::query()
                ->active()
                ->orderBy('name')
                ->get()
                ->map(fn (MonetisationPolicy $policy): array => MonetisationPolicyResource::make($policy)->resolve($request)),
            'defaultPolicy' => $default === null ? null : MonetisationPolicyResource::make($default)->resolve($request),
        ]);
    }

    /**
     * Move a seller onto a policy, or back onto the platform default.
     *
     * A null slug means the default, which is where nearly every seller
     * stays. Audited on both sides: "who moved this shop onto the bespoke 4%,
     * and when" is the first question anybody asks about a payout that looks
     * wrong.
     */
    public function update(Request $request, Seller $seller): RedirectResponse
    {
        Gate::authorize('manage', MonetisationPolicy::class);

        $data = $request->validate([
            'policy' => ['nullable', 'string', 'exists:monetisation_policies,slug'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $policy = ($data['policy'] ?? null) === null
            ? null
            : MonetisationPolicy::query()->where('slug', $data['policy'])->first();

        $this->policies->assign($seller, $policy, $this->currentUser($request), $data['reason'] ?? null);

        return back()->with('success', __(':seller is now on :policy.', [
            'seller' => $seller->business_name,
            'policy' => $policy->name ?? __('the platform default'),
        ]));
    }
}
