<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Payments\Events\PaymentFailed;
use App\Support\Alerts\AlertDispatcher;
use App\Support\Alerts\AlertLevel;

/**
 * Tells the platform's operators when collections start failing.
 *
 * ## Not every failed payment is news
 *
 * A buyer who mistypes a mobile-money PIN, or has no float, produces a failed
 * payment that nobody needs to hear about — it is the system working. What is
 * worth an interruption is the shape of failure that means MonaFind itself is
 * broken: an authentication error against the gateway, an endpoint refusing
 * everything, a misconfigured account id.
 *
 * So the alert is keyed on the gateway's failure reason rather than on the
 * payment. One buyer's wrong PIN is suppressed behind the cooldown for that
 * reason; a reason that suddenly applies to every attempt gets through once
 * and then reports how many it is standing for.
 *
 * Synchronous by design: the dispatcher writes a log line and, at most, queues
 * one mail. Putting that behind a job would mean the alert about the payments
 * queue being broken was itself queued on the payments queue.
 */
class AlertOnPaymentFailure
{
    public function __construct(private readonly AlertDispatcher $alerts) {}

    public function handle(PaymentFailed $event): void
    {
        $payment = $event->payment;

        $reason = $payment->failure_reason ?? 'unknown';

        $this->alerts->raise(
            key: 'payments.failed:'.mb_substr($reason, 0, 120),
            level: AlertLevel::Warning,
            summary: 'A payment collection failed at the gateway.',
            context: [
                'reference' => $payment->reference,
                'channel' => $payment->channel?->value,
                'amount_ngwee' => $payment->amount_ngwee->ngwee,
                'reason' => mb_substr($reason, 0, 300),
            ],
            actionUrl: url('/admin/reconciliation'),
            actionLabel: 'Open reconciliation',
        );
    }
}
