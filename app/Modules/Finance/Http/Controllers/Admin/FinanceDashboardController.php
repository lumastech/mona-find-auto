<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Enums\MetricDimension;
use App\Modules\Finance\Enums\MetricGranularity;
use App\Modules\Finance\Services\FinanceMetrics;
use App\Modules\Finance\Support\MetricWindow;
use App\Modules\Payments\Services\PlatformCashCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's own numbers, read off the ledger.
 *
 * Everything on this screen comes from FinanceMetrics, which sums journal
 * lines and nothing else — see that class for why a GMV figure taken from the
 * orders table would be a different and less trustworthy number.
 *
 * The cash check is DEFERRED because it calls Lenco over the network, and the
 * figures above it are what somebody opened this page for. A dashboard that
 * waits on a third party to render its first byte is a dashboard people stop
 * opening.
 */
class FinanceDashboardController extends Controller
{
    public function __construct(
        private readonly FinanceMetrics $metrics,
        private readonly PlatformCashCheck $cashCheck,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('finance');

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'granularity' => ['nullable', 'string', 'max:16'],
            'dimension' => ['nullable', 'string', 'max:32'],
        ]);

        $window = MetricWindow::fromStrings($filters['from'] ?? null, $filters['to'] ?? null);
        $granularity = MetricGranularity::tryFrom($filters['granularity'] ?? '') ?? MetricGranularity::Day;
        $dimension = MetricDimension::tryFrom($filters['dimension'] ?? '') ?? MetricDimension::SellerType;

        return Inertia::render('admin/finance/Dashboard', [
            'window' => $window->toArray(),
            'granularity' => $granularity->value,
            'dimension' => $dimension->value,

            'totals' => $this->metrics->totals($window)->toArray(),
            'positions' => $this->metrics->positions($window)->toArray(),
            'series' => $this->metrics->series($window, $granularity),
            'breakdown' => $this->metrics->breakdown($window, $dimension),

            'granularities' => MetricGranularity::options(),
            'dimensions' => MetricDimension::options(),

            /* Over the network to Lenco — never in the critical path of the page. */
            'cashCheck' => Inertia::defer(fn (): array => $this->cashCheck->run()),
        ]);
    }
}
