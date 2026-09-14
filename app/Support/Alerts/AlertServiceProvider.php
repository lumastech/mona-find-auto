<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the platform's operational alerts to the things worth waking up for.
 *
 * Only one of the three lives here: a failed queue job is a framework event
 * with no owning module. The other two — payment failures and reconciliation
 * exceptions — are raised by Payments' own listeners, because what counts as
 * an alarming payment failure is Payments' business, not this file's.
 */
class AlertServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AlertDispatcher::class);
    }

    public function boot(): void
    {
        $this->alertOnFailedJobs();
    }

    /**
     * Every side effect on this platform is a queued job — webhooks, payouts,
     * refunds, reconciliation, notifications. A job that fails silently is
     * money that stops moving while the site carries on looking fine, which
     * is the single most expensive failure mode available.
     *
     * Alerts are keyed on the job class, so a crash-looping worker produces
     * one email rather than one per attempt; the suppressed count rides along
     * on the next one that gets through.
     */
    private function alertOnFailedJobs(): void
    {
        Queue::failing(function (JobFailed $event): void {
            $job = $event->job->resolveName();

            /*
             * Money queues are critical. A failed notification is worth
             * knowing about; a failed payout is worth interrupting somebody.
             */
            $level = $event->job->getQueue() === config('monafind.queues.payments')
                ? AlertLevel::Critical
                : AlertLevel::Warning;

            app(AlertDispatcher::class)->raise(
                key: 'queue.failed:'.$job,
                level: $level,
                summary: sprintf('A queued job failed: %s', class_basename($job)),
                context: [
                    'job' => $job,
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                    'attempts' => $event->job->attempts(),
                    'exception' => mb_substr($event->exception->getMessage(), 0, 500),
                ],
                actionUrl: url('/horizon/failed'),
                actionLabel: 'Open Horizon',
            );
        });
    }
}
