<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Enums\ReconciliationStatus;
use App\Modules\Payments\Models\ReconciliationRun;
use App\Modules\Payments\Services\ReconciliationService;
use App\Support\Alerts\AlertDispatcher;
use App\Support\Alerts\AlertLevel;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Reconcile a day, nightly.
 *
 * Defaults to YESTERDAY rather than today. Lenco settles next-day and a
 * day is not finished until it is over; reconciling the current day would
 * flag every collection made in the last hour as unsettled and drown the
 * genuine exceptions in noise.
 */
class ReconcileGatewayDay implements ShouldQueue
{
    use Queueable;

    /**
     * Retried, because the failure this most often hits is the gateway being
     * briefly unavailable and the whole run is safe to repeat — a re-run
     * replaces the day rather than adding to it.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [300, 900];

    public function __construct(public readonly ?string $date = null) {}

    public function handle(ReconciliationService $reconciliation, AlertDispatcher $alerts): void
    {
        $run = $reconciliation->run($this->targetDate());

        $this->alertOnExceptions($run, $alerts);
    }

    /**
     * A day that did not reconcile is somebody's morning.
     *
     * The alert exists because the reconciliation console is a screen nobody
     * has a reason to open on a day when everything balanced — which is most
     * days, which is exactly why an exception would otherwise sit there
     * unread. The variance is in the summary rather than only in the body, so
     * the severity is legible from a phone's lock screen.
     */
    private function alertOnExceptions(ReconciliationRun $run, AlertDispatcher $alerts): void
    {
        if ($run->status !== ReconciliationStatus::Exceptions) {
            return;
        }

        $alerts->raise(
            /*
             * Keyed on the date, so re-running a day does not re-alert and a
             * genuinely new day always does.
             */
            key: 'payments.reconciliation:'.$run->for_date->toDateString(),
            level: AlertLevel::Critical,
            summary: sprintf(
                '%d reconciliation exception(s) for %s.',
                $run->exception_count,
                $run->for_date->toDateString(),
            ),
            context: [
                'date' => $run->for_date->toDateString(),
                'exceptions' => $run->exception_count,
                'gateway_total_ngwee' => $run->gateway_total_ngwee->ngwee,
                'ledger_total_ngwee' => $run->ledger_total_ngwee->ngwee,
                'variance_ngwee' => $run->variance_ngwee->ngwee,
            ],
            actionUrl: url('/admin/reconciliation'),
            actionLabel: 'Open reconciliation',
        );
    }

    private function targetDate(): CarbonInterface
    {
        return $this->date !== null
            ? Carbon::parse($this->date)
            : Carbon::now()->subDay();
    }
}
