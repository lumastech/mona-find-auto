<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Api;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Sellers\Http\Resources\SellerProfileResource;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Public seller profiles for the mobile app.
 */
class SellerController extends Controller
{
    public function __construct(private readonly SellerPolicyService $policies) {}

    /**
     * Browse sellers.
     *
     * Guests may list and read sellers, exactly as they may browse the
     * storefront. Contact details follow the same blur rule as the web page:
     * labels and a masked shape until the caller is authenticated.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'province_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
            'verified' => ['nullable', 'boolean'],
        ]);

        $sellers = Seller::query()
            ->publiclyVisible()
            ->with(['province', 'city'])
            ->search($filters['search'] ?? null)
            ->when($filters['province_id'] ?? null, fn ($query, int $id) => $query->where('province_id', $id))
            ->when($filters['city_id'] ?? null, fn ($query, int $id) => $query->where('city_id', $id))
            ->when($filters['verified'] ?? null, fn ($query) => $query->verified())
            ->orderBy('business_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Seller $seller): array => SellerProfileResource::make($seller)->resolve($request));

        return ApiResponse::paginated($sellers);
    }

    /**
     * One seller's public profile, with their current policies.
     */
    public function show(Request $request, Seller $seller): JsonResponse
    {
        abort_unless($seller->verification_status->isPubliclyVisible(), HttpResponse::HTTP_NOT_FOUND);

        $seller->load(['province', 'city', 'currentPolicies']);

        return ApiResponse::ok(
            SellerProfileResource::make($seller)->resolve($request),
            ['platform_minimum_refund' => $this->policies->platformMinimumRefund()],
        );
    }
}
