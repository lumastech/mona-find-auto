<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Search\Services\SearchAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What buyers searched for and did not find.
 *
 * This is the reference-data backlog, written by buyers. A term at the top of
 * this list is one of three things: a make or model the reference lists are
 * missing, a part category nobody thought to create, or a genuine gap in what
 * Zambian sellers are stocking. All three are worth somebody's morning, and
 * none of them are visible anywhere else in the console.
 */
class SearchInsightsController extends Controller
{
    public function __construct(private readonly SearchAnalytics $analytics) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('staff');

        $filters = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($filters['days'] ?? SearchAnalytics::DEFAULT_REPORT_DAYS);

        return Inertia::render('admin/search/Insights', [
            'days' => $days,
            'summary' => $this->analytics->summary($days),
            'zeroResultTerms' => $this->analytics->topZeroResultTerms($days),
            'topTerms' => $this->analytics->topTerms($days),
        ]);
    }
}
