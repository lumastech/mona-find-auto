<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Payments\Exceptions\PaymentUnavailable;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CollectionService;
use App\Modules\Payments\Services\WidgetConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The buyer's side of paying.
 *
 * ## The verify endpoint takes no input
 *
 * `verify()` accepts a group and nothing else. Not a status, not an amount,
 * not a gateway id — the widget's `onSuccess` is a hint that it is worth
 * asking Lenco, and this endpoint goes and asks. A buyer replaying it from
 * the console achieves exactly one thing: a second status check.
 *
 * ## Pending is a screen, not an error
 *
 * A mobile-money payment sits on a handset waiting for a PIN, sometimes for
 * minutes. That is a normal state with its own page and its own polling, not
 * a failure — and the poller behind it means a buyer who closes the tab still
 * gets their order when the money lands.
 */
class PaymentController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly CollectionService $collections,
        private readonly WidgetConfigurator $widget,
    ) {}

    /**
     * The pay screen, with everything the widget needs.
     */
    public function show(Request $request, OrderGroup $group): Response|RedirectResponse
    {
        $this->authoriseGroup($request, $group);

        if ($group->isPaid()) {
            return to_route('payments.status', $group->public_id);
        }

        try {
            $attempt = $this->collections->begin($group);
        } catch (PaymentUnavailable $exception) {
            return to_route('orders.index')->with('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }

        $group->loadMissing('orders.seller');

        return Inertia::render('storefront/payments/Pay', [
            'group' => [
                'publicId' => $group->public_id,
                'totalNgwee' => $group->total_ngwee->ngwee,
                'itemsTotalNgwee' => $group->items_total_ngwee->ngwee,
                'deliveryTotalNgwee' => $group->delivery_total_ngwee->ngwee,
                'sellers' => $group->orders->map(static fn ($order): array => [
                    'number' => $order->number,
                    'name' => $order->seller->business_name,
                    'totalNgwee' => $order->total_ngwee->ngwee,
                ])->values(),
            ],
            /* Public key only. See WidgetConfigurator — this is the boundary. */
            'lenco' => $this->widget->forAttempt($group, $attempt, $this->currentUser($request)),
        ]);
    }

    /**
     * Ask the gateway what happened. Called by the widget's onSuccess.
     *
     * Returns JSON because the widget calls it from JavaScript mid-overlay;
     * the browser decides where to go next from the status it gets back.
     */
    public function verify(Request $request, OrderGroup $group): JsonResponse
    {
        $this->authoriseGroup($request, $group);

        $reference = $this->collections->currentReference($group);

        if ($reference === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        $payment = $this->collections->verify($reference);

        return response()->json([
            'status' => $payment->status->value,
            'reference' => $payment->reference,
            'redirect' => route('payments.status', $group->public_id),
        ]);
    }

    /**
     * Where the buyer waits, and where they end up.
     *
     * One page for pending, success and failure rather than three routes: the
     * state can change while the buyer is looking at it, and a page that polls
     * itself is simpler than three that redirect between each other.
     */
    public function status(Request $request, OrderGroup $group): Response
    {
        $this->authoriseGroup($request, $group);

        $group->loadMissing('orders.seller');

        $reference = $this->collections->currentReference($group);
        $payment = $reference === null ? null : Payment::currentFor($reference);

        return Inertia::render('storefront/payments/Status', [
            'group' => [
                'publicId' => $group->public_id,
                'status' => $group->status->value,
                'statusLabel' => $group->status->label(),
                'totalNgwee' => $group->total_ngwee->ngwee,
                'isPaid' => $group->isPaid(),
                'orders' => $group->orders->map(static fn ($order): array => [
                    'number' => $order->number,
                    'seller' => $order->seller->business_name,
                    'totalNgwee' => $order->total_ngwee->ngwee,
                    'url' => route('orders.show', $order->number),
                ])->values(),
            ],
            'payment' => $payment === null ? null : [
                'reference' => $payment->reference,
                'status' => $payment->status->value,
                'attempt' => $payment->attempt,
                'channel' => $payment->channel?->label(),
                'failureReason' => $payment->failure_reason,
                'amountNgwee' => $payment->amount_ngwee->ngwee,
            ],
            /* The page polls while this is true, and stops when it is not. */
            'isPending' => $payment?->status === PaymentStatus::Pending && ! $group->isPaid(),
            'retryUrl' => $group->isPaid() ? null : route('payments.show', $group->public_id),
            'pollUrl' => route('payments.poll', $group->public_id),
        ]);
    }

    /**
     * The pending page's heartbeat.
     *
     * Re-asks the gateway rather than reading our own row, because the whole
     * reason the buyer is on this page is that nothing has told us yet.
     */
    public function poll(Request $request, OrderGroup $group): JsonResponse
    {
        $this->authoriseGroup($request, $group);

        $reference = $this->collections->currentReference($group);

        if ($reference === null) {
            return response()->json(['status' => 'unknown', 'isPaid' => $group->isPaid()]);
        }

        $payment = $this->collections->verify($reference);

        return response()->json([
            'status' => $payment->status->value,
            'isPaid' => $group->refresh()->isPaid(),
            'failureReason' => $payment->failure_reason,
        ]);
    }

    /**
     * A buyer may only ever touch their own payment.
     */
    private function authoriseGroup(Request $request, OrderGroup $group): void
    {
        if (! $this->collections->canBePaidBy($group, $this->currentUser($request))) {
            throw new AccessDeniedHttpException('This is not your order.');
        }
    }
}
