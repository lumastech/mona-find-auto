<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Ledger\Enums\CommissionType;
use App\Modules\Ledger\Http\Requests\Admin\MonetisationPolicyRequest;
use App\Modules\Ledger\Http\Resources\MonetisationPolicyResource;
use App\Modules\Ledger\Models\MonetisationPolicy;
use App\Modules\Ledger\Services\MonetisationPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The monetisation policy manager.
 *
 * Editing here is safe and the screen says so, because it looks as though it
 * should not be: no order ever reads one of these rows. Terms are snapshotted
 * onto an order when the money arrives, so raising a commission changes what
 * tomorrow's orders are charged and cannot reach a sale already settled.
 *
 * Deleting is deliberately absent. A policy is retired instead, and its
 * sellers keep it until somebody moves them — pulling a shop onto different
 * terms as a side effect of tidying up a list is not something an
 * administrator should be able to do by accident.
 */
class MonetisationPolicyController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly MonetisationPolicyService $policies) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MonetisationPolicy::class);

        $policies = MonetisationPolicy::query()
            ->withCount('sellers')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (MonetisationPolicy $policy): array => MonetisationPolicyResource::make($policy)->resolve($request));

        return Inertia::render('admin/finance/policies/Index', [
            'policies' => $policies,
            'commissionTypes' => CommissionType::options(),
            'platform' => [
                /*
                 * VAT and the reserve are platform-wide and are not part of a
                 * policy. Shown here anyway, because an administrator pricing
                 * a seller is working out a payout and needs both figures in
                 * front of them.
                 */
                'vat_on_commission_percent' => (string) settings('monetisation.vat_on_commission_percent', '0.00'),
                'reserve_percent' => (string) settings('risk.reserve_percent', '0.00'),
            ],
        ]);
    }

    public function store(MonetisationPolicyRequest $request): RedirectResponse
    {
        Gate::authorize('manage', MonetisationPolicy::class);

        $policy = $this->policies->create($request->policyAttributes(), $this->currentUser($request));

        return back()->with('success', __(':name created.', ['name' => $policy->name]));
    }

    public function update(MonetisationPolicyRequest $request, MonetisationPolicy $policy): RedirectResponse
    {
        Gate::authorize('manage', MonetisationPolicy::class);

        $this->policies->update(
            $policy,
            $request->policyAttributes(),
            $this->currentUser($request),
            $request->validated('reason'),
        );

        return back()->with('success', __(':name updated. Orders already paid keep the terms they were settled on.', [
            'name' => $policy->name,
        ]));
    }

    /**
     * Make a policy the one sellers without an override fall back to.
     */
    public function makeDefault(Request $request, MonetisationPolicy $policy): RedirectResponse
    {
        Gate::authorize('manage', MonetisationPolicy::class);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        $this->policies->makeDefault($policy, $this->currentUser($request), $reason);

        return back()->with('success', __(':name is now the default.', ['name' => $policy->name]));
    }

    /**
     * Retire a policy, or bring one back.
     */
    public function toggleActive(Request $request, MonetisationPolicy $policy): RedirectResponse
    {
        Gate::authorize('manage', MonetisationPolicy::class);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        if ($policy->is_default) {
            return back()->with('error', __('The default policy cannot be retired. Make another policy the default first.'));
        }

        if ($policy->is_active) {
            $this->policies->deactivate($policy, $this->currentUser($request), $reason);
        } else {
            $this->policies->reactivate($policy, $this->currentUser($request), $reason);
        }

        return back()->with('success', __(':name updated.', ['name' => $policy->name]));
    }
}
