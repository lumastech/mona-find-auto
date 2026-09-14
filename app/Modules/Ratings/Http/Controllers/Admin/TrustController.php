<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Ratings\Http\Resources\TrustScoreResource;
use App\Modules\Ratings\Models\SellerTrustScore;
use App\Modules\Ratings\Services\TrustScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The low-rating review list.
 *
 * Sellers whose trust score has fallen into Watch or Critical, or whose
 * dispute rate is above the platform threshold — two independent reasons,
 * because a shop with a handful of glowing reviews and a rising dispute rate
 * is exactly the case a score alone misses.
 *
 * Each row carries what the platform recommends doing about it. That is the
 * difference between a worklist and a leaderboard: a moderator opening this
 * should know what the next step is without having to decide the policy
 * themselves.
 */
class TrustController extends Controller
{
    public function __construct(private readonly TrustScoreService $trust) {}

    public function index(Request $request): Response
    {
        Gate::authorize('moderate');

        $sellers = $this->trust->reviewList()
            ->map(fn (SellerTrustScore $score): array => TrustScoreResource::make($score)->resolve($request))
            ->all();

        return Inertia::render('admin/ratings/Trust', [
            'sellers' => $sellers,
            'disputeThreshold' => (float) settings('risk.dispute_rate_threshold_percent', 2),
        ]);
    }
}
