<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Http\Requests\Seller\SellerPolicyRequest;
use App\Modules\Sellers\Http\Resources\SellerPolicyResource;
use App\Modules\Sellers\Services\SellerPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A seller's policy manager.
 *
 * Saving publishes a new version rather than editing the old one, and the
 * history stays on screen — a seller should be able to see what a buyer
 * agreed to six weeks ago, because that is the text a dispute will be argued
 * against.
 */
class PolicyController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(private readonly SellerPolicyService $policies) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        return Inertia::render('seller/Policies', [
            'policyTypes' => PolicyType::options(),
            'current' => array_map(
                static fn ($policy): array => SellerPolicyResource::make($policy)->resolve(),
                $this->policies->currentFor($seller),
            ),
            'history' => SellerPolicyResource::collection($seller->policies()->get())->resolve(),
            'platformMinimumRefund' => $this->policies->platformMinimumRefund(),
        ]);
    }

    public function store(SellerPolicyRequest $request): RedirectResponse
    {
        $seller = $this->currentSeller($request);
        $type = $request->policyType();

        $policy = $this->policies->publish(
            $seller,
            $type,
            $request->string('body')->toString(),
            $this->currentUser($request),
            $request->date('effective_from'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':policy saved as version :version.', [
                'policy' => $type->label(),
                'version' => $policy->version,
            ]),
        ]);

        return to_route('seller.policies.index');
    }
}
