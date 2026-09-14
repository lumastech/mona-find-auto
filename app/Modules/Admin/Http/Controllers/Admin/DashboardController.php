<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Services\StaffDirectory;
use App\Support\Console\ConsoleCounter;
use App\Support\Console\ConsoleCounters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff console's home screen: what is waiting, and nothing else.
 *
 * Every number on it belongs to another module — see ConsoleCounters for why
 * this controller asks rather than counts. What it adds is the two things
 * only Admin knows: the shortcuts staff actually use, and whether anybody on
 * the console still has two-factor authentication to set up.
 *
 * A tile a viewer's role cannot act on is not rendered greyed out, it is
 * absent. A moderator has no business knowing how many payout batches are
 * waiting, and a dashboard full of doors that refuse you is one nobody reads.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ConsoleCounters $counters,
        private readonly StaffDirectory $staff,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('admin/Dashboard', [
            'counters' => array_map(
                static fn (ConsoleCounter $counter): array => $counter->toArray(),
                $this->counters->for($user),
            ),
            'shortcuts' => $this->shortcutsFor($user),
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
