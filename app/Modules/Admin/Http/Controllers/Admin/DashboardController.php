<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Services\StaffDirectory;
use App\Support\Console\ConsoleChart;
use App\Support\Console\ConsoleCounter;
use App\Support\Console\ConsoleCounters;
use App\Support\Console\ConsoleStat;
use App\Support\Console\ConsoleStatistics;
use App\Support\Console\ConsoleWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff console's home screen: what is waiting, then how it is going.
 *
 * Every number on it belongs to another module — see ConsoleCounters and
 * ConsoleStatistics for why this controller asks rather than counts. What it
 * adds is the two things only Admin knows: the shortcuts staff actually use,
 * and whether anybody on the console still has two-factor authentication to
 * set up.
 *
 * A tile a viewer's role cannot act on is not rendered greyed out, it is
 * absent. A moderator has no business knowing how many payout batches are
 * waiting, and a dashboard full of doors that refuse you is one nobody reads.
 *
 * The two halves are ordered and loaded deliberately. Queues come first and
 * arrive with the page: they are the reason somebody opened the screen, and
 * they are cheap aggregate counts. The statistics come second and are
 * deferred, because each one walks a thirty-day window twice — once for now
 * and once for the fortnight before it, which is the only thing that makes a
 * figure mean anything — and none of that should hold up a dispute count.
 */
class DashboardController extends Controller
{
    /**
     * Thirty days, compared against the thirty before it.
     *
     * Long enough that a quiet week does not read as a collapse, short enough
     * that a change made this month is visible in it. Not configurable: this
     * is a glance, and the finance dashboard is where a window gets chosen.
     */
    private const WINDOW_DAYS = 30;

    public function __construct(
        private readonly ConsoleCounters $counters,
        private readonly ConsoleStatistics $statistics,
        private readonly StaffDirectory $staff,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $window = ConsoleWindow::lastDays(self::WINDOW_DAYS);

        return Inertia::render('admin/Dashboard', [
            'counters' => array_map(
                static fn (ConsoleCounter $counter): array => $counter->toArray(),
                $this->counters->for($user),
            ),
            'shortcuts' => $this->shortcutsFor($user),
            'window' => $window->toArray(),
            /*
             * Deferred: it walks every staff account to ask each whether it
             * has enrolled, and the tiles above are what somebody opened this
             * screen for.
             */
            'twoFactorOutstanding' => Inertia::defer(
                fn (): ?int => Gate::forUser($user)->allows('admin-only')
                    ? $this->staff->awaitingTwoFactor()
                    : null,
            ),
            /*
             * One group, so the strip and the charts under it arrive in a
             * single follow-up request and land together. Split across two
             * groups they would pop in one after the other and shift the page
             * twice under somebody already reading it.
             */
            'stats' => Inertia::defer(
                fn (): array => array_map(
                    static fn (ConsoleStat $stat): array => $stat->toArray(),
                    $this->statistics->statsFor($user, $window),
                ),
                'insights',
            ),
            'charts' => Inertia::defer(
                fn (): array => array_map(
                    static fn (ConsoleChart $chart): array => $chart->toArray(),
                    $this->statistics->chartsFor($user, $window),
                ),
                'insights',
            ),
        ]);
    }

    /**
     * The screens with no queue behind them, so no tile.
     *
     * @return array<int, array{title: string, href: string, description: string}>
     */
    private function shortcutsFor(User $user): array
    {
        $shortcuts = [];

        if (Gate::forUser($user)->allows('moderate')) {
            $shortcuts[] = [
                'title' => 'Reference data',
                'href' => route('admin.reference-data.index'),
                'description' => 'Makes, models, categories, specialities and towns — with merge tooling for duplicates.',
            ];
            $shortcuts[] = [
                'title' => 'Pages and announcements',
                'href' => route('admin.content.index'),
                'description' => 'What MonaFind publishes about itself, and the banner across the top.',
            ];
            $shortcuts[] = [
                'title' => 'Search insights',
                'href' => route('admin.search-insights'),
                'description' => 'The searches buyers ran that came back empty.',
            ];
        }

        if (Gate::forUser($user)->allows('finance')) {
            $shortcuts[] = [
                'title' => 'Ledger',
                'href' => route('admin.finance.ledger.index'),
                'description' => 'Every balanced entry the platform has posted.',
            ];
        }

        if (Gate::forUser($user)->allows('admin-only')) {
            $shortcuts[] = [
                'title' => 'Platform settings',
                'href' => route('admin.settings.index'),
                'description' => 'Escrow windows, ranking weights, commission and reserves.',
            ];
            $shortcuts[] = [
                'title' => 'Staff',
                'href' => route('admin.staff.index'),
                'description' => 'Who works here, what they may touch, and who is still to enrol in 2FA.',
            ];
        }

        $shortcuts[] = [
            'title' => 'Audit trail',
            'href' => route('admin.audit.index'),
            'description' => 'Every staff action and every money movement, in order.',
        ];

        return $shortcuts;
    }
}
