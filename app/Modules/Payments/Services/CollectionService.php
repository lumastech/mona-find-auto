<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Models\User;
use App\Modules\Orders\Enums\OrderGroupStatus;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Orders\Services\OrderPaymentService;
use App\Modules\Payments\Enums\PaymentSource;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Exceptions\PaymentUnavailable;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Taking the buyer's money.
 *
 * ## The rule this whole class exists to enforce
 *
 * A payment is settled because the SERVER asked Lenco and Lenco said yes.
 * Never because the browser said so. `onSuccess` in the widget is a hint that
 * it is worth asking — nothing more — and the verify endpoint it posts to
 * ignores everything in the request except which reference to go and check.
 * Anything else would let a buyer settle their own order from the console.
 *
 * ## Three routes to the same place
 *
 * The browser's verify call, Lenco's webhook and the stuck-payment poller all
 * end up in `settle()`. Which of them arrives first does not matter, because
 * the observation is written under a unique index: the winner settles the
 * orders and fires the event, the losers find the row already there and stop.
 * That is the entire concurrency story, and it lives in PaymentRecorder.
 *
 * ## Attempts
 *
 * A declined card is not the end of a checkout. Each retry takes a new
 * attempt number and therefore a new reference, because Lenco refuses a
 * reference it has seen before, while the OrderGroup stays exactly the same —
 * the buyer is still buying the same things from the same shops.
 */
class CollectionService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentRecorder $recorder,
        private readonly OrderPaymentService $orders,
    ) {}

    /**
     * Start an attempt and hand back what the widget needs.
     *
     * @return array{reference: string, attempt: int, amount_ngwee: int, amount: string}
     */
    public function begin(OrderGroup $group): array
    {
        $this->assertPayable($group);

        $reference = $this->orders->beginAttempt($group);
        $group->refresh();

        $this->recorder->recordInitiated($group, $reference, $group->payment_attempts);

        return [
            'reference' => $reference,
            'attempt' => $group->payment_attempts,
            'amount_ngwee' => $group->total_ngwee->ngwee,
            /* Lenco takes decimal kwacha, not minor units. */
            'amount' => $group->total_ngwee->toDecimalString(),
        ];
    }

    /**
     * Push a mobile-money prompt to a handset — the API's way to pay.
     *
     * The response is all but always pending: the money arrives when the
     * customer keys in their PIN, and the webhook tells us about it.
     */
    public function collectMobileMoney(OrderGroup $group, string $phone, string $network): Payment
    {
        $this->assertPayable($group);

        $reference = $this->orders->beginAttempt($group);
        $group->refresh();

        $collection = $this->gateway->collectMobileMoney(
            $group->total_ngwee,
            $reference,
            $phone,
            $network,
            ['order_group' => $group->public_id],
        );

        $payment = $this->recorder->record($collection, PaymentSource::Initiate, $group, $group->payment_attempts)
            ?? Payment::currentFor($reference)
            ?? throw PaymentUnavailable::notConfigured();

        if ($collection->status === PaymentStatus::Successful) {
            $this->settle($reference, PaymentSource::Initiate);
        }

        return $payment;
    }

    /**
     * Ask the gateway what really happened, and act on the answer.
     *
     * The single entry point for all three confirmation routes. Everything it
     * does is driven by the gateway's own answer to a reference — the caller
     * supplies no status, no amount and no opinion.
     */
    public function verify(string $reference, PaymentSource $source = PaymentSource::Verify): Payment
    {
        $collection = $this->gateway->fetchCollection($reference);

        return match ($collection->status) {
            PaymentStatus::Successful => $this->settle($reference, $source),
            PaymentStatus::Failed, PaymentStatus::Reversed => $this->fail($reference, $source),
            PaymentStatus::Pending => $this->recordPending($reference, $source),
        };
    }

    /**
     * The money is confirmed. Record it, release the orders, tell the world.
     *
     * Re-reads the collection rather than trusting a caller's copy, so that a
     * webhook body — which is attacker-shaped input until proven otherwise —
     * can never decide an amount.
     *
     * Idempotent by construction: if the successful observation was already
     * recorded, this returns it without settling anything a second time.
     */
    public function settle(string $reference, PaymentSource $source = PaymentSource::Verify): Payment
    {
        $collection = $this->gateway->fetchCollection($reference);

        if (! $collection->status->isSettled()) {
            return $this->recordPending($reference, $source);
        }

        $payment = DB::transaction(function () use ($collection, $source): ?Payment {
            $recorded = $this->recorder->record($collection, $source);

            if ($recorded === null) {
                /* Somebody else confirmed this first. Nothing left to do. */
                return null;
            }

            $group = $recorded->orderGroup;

            if ($group !== null) {
                $this->orders->settle($group, 'Payment '.$recorded->reference.' confirmed.');
            }

            return $recorded;
        });

        if ($payment === null) {
            return Payment::currentFor($reference) ?? throw PaymentUnavailable::unknownReference();
        }

        PaymentSucceeded::dispatch($payment);

        return $payment;
    }

    /**
     * The attempt failed for good.
     *
     * The orders are NOT cancelled here. A declined card is a buyer who will
     * very likely try again in ten seconds, and cancelling their orders would
     * take the stock back down and make them rebuild the cart. Abandonment is
     * the auto-cancel sweep's job, on its own timetable.
     */
    public function fail(string $reference, PaymentSource $source = PaymentSource::Verify): Payment
    {
        $collection = $this->gateway->fetchCollection($reference);

        /*
         * A late failure on a reference already confirmed successful is
         * ignored. It happens — a retried webhook arriving out of order — and
         * acting on it would unpay a paid order.
         */
        if (Payment::isSettled($reference)) {
            return Payment::currentFor($reference) ?? throw PaymentUnavailable::unknownReference();
        }

        $payment = $this->recorder->record($collection, $source);

        if ($payment === null) {
            return Payment::currentFor($reference) ?? throw PaymentUnavailable::unknownReference();
        }

        PaymentFailed::dispatch($payment);

        return $payment;
    }

    /**
     * Still waiting. Recorded so the poller can find it later.
     */
    private function recordPending(string $reference, PaymentSource $source): Payment
    {
        $collection = $this->gateway->fetchCollection($reference);

        return $this->recorder->record($collection, $source)
            ?? Payment::currentFor($reference)
            ?? throw PaymentUnavailable::unknownReference();
    }

    /**
     * Whether this group can be paid at all.
     *
     * @throws PaymentUnavailable
     */
    private function assertPayable(OrderGroup $group): void
    {
        if ($group->isPaid()) {
            throw PaymentUnavailable::alreadyPaid();
        }

        if ($group->status === OrderGroupStatus::Cancelled) {
            throw PaymentUnavailable::notPayable();
        }

        if (! $group->total_ngwee->isPositive()) {
            throw PaymentUnavailable::nothingToPay();
        }
    }

    /**
     * The reference of the attempt currently in flight on a group.
     *
     * Rebuilt through the gateway rather than stored on the group, so the
     * format has exactly one implementation. Null before the first attempt:
     * there is nothing in flight to ask about.
     */
    public function currentReference(OrderGroup $group): ?string
    {
        return $group->payment_attempts < 1
            ? null
            : $this->gateway->reference($group->public_id, $group->payment_attempts);
    }

    /**
     * Whether a buyer may pay for this group.
     */
    public function canBePaidBy(OrderGroup $group, User $user): bool
    {
        return $group->user_id === $user->getKey();
    }
}
