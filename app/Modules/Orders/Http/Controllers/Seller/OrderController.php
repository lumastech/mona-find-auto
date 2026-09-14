<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Exceptions\UnauthorisedOrderAction;
use App\Modules\Orders\Http\Requests\Seller\FulfilmentActionRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderDocumentService;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use App\Modules\Ratings\Services\RatingEligibility;
use App\Modules\Ratings\Support\RatingPrompt;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The seller's order inbox.
 *
 * Opens on Paid — the orders that owe the buyer an answer and are counting
 * down towards auto-cancellation. A seller who opens their portal and sees an
 * archive first is a seller whose orders get cancelled.
 *
 * The four fulfilment actions all funnel into the same two state-machine
 * calls, and which state they produce comes from the ORDER's fulfilment
 * method rather than from the button pressed. That is why there is no
 * "dispatch" action separate from "mark ready": a pickup order cannot be
 * dispatched, and making that a routing decision would make it possible.
 */
class OrderController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly OrderDocumentService $documents,
        private readonly RatingEligibility $ratings,
    ) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $status = OrderStatus::tryFrom($filters['status'] ?? '');

        $orders = Order::query()
            ->forSeller($seller)
            ->withStatus($status)
            ->search($filters['search'] ?? null)
            ->with(['items', 'buyer', 'disputes'])
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $order): array => OrderResource::make($order)->resolve($request));

        return Inertia::render('seller/orders/Index', [
            'orders' => $orders,
            'filters' => ['status' => $status?->value, 'search' => $filters['search'] ?? null],
            'statuses' => array_map(
                static fn (OrderStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                OrderStatus::sellerInboxOrder(),
            ),
            'counts' => $this->countsByStatus($seller->getKey()),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        Gate::authorize('fulfil', $order);

        $order->load(['items', 'buyer', 'statusEvents.actor', 'disputes.media', 'group', 'termsAcceptance']);

        return Inertia::render('seller/orders/Show', [
            'order' => OrderResource::make($order)->resolve($request),
            /*
             * What this person may still rate about this order. Asked of
             * Ratings rather than worked out here, so the card the page shows
             * and the guard on the endpoint it posts to are one decision.
             */
            'ratingPrompts' => array_map(
                static fn (RatingPrompt $prompt): array => $prompt->toArray(),
                $this->ratings->promptsFor($order, $this->currentUser($request)),
            ),
            'payout' => [
                'commission_ngwee' => $order->commission()->ngwee,
                'payout_ngwee' => $order->sellerPayout()->ngwee,
                'terms' => $order->monetisation()?->commissionDescription(),
                'payment_mode' => $order->payment_mode?->value,
                'payment_mode_label' => $order->payment_mode?->label(),
            ],
        ]);
    }

    /**
     * "Yes, I have it and I am getting it ready." Stops the auto-cancel clock.
     */
    public function confirm(FulfilmentActionRequest $request, Order $order): RedirectResponse
    {
        return $this->move($request, $order, fn (OrderActor $actor): Order => $this->orders->confirm($order, $actor, $request->note()),
            __('Order confirmed. The buyer has been told.'));
    }

    /**
     * On the counter, or on its way — whichever this order is.
     */
    public function markReady(FulfilmentActionRequest $request, Order $order): RedirectResponse
    {
        return $this->move($request, $order, fn (OrderActor $actor): Order => $this->orders->markReady($order, $actor, $request->note()),
            __('The buyer has been told.'));
    }

    /**
     * Collected or delivered. Starts the buyer's checking window.
     */
    public function markHandedOver(FulfilmentActionRequest $request, Order $order): RedirectResponse
    {
        return $this->move($request, $order, fn (OrderActor $actor): Order => $this->orders->markHandedOver($order, $actor, $request->note()),
            __('Marked as handed over. The buyer now has time to check the part.'));
    }

    public function cancel(FulfilmentActionRequest $request, Order $order): RedirectResponse
    {
        return $this->move($request, $order, fn (OrderActor $actor): Order => $this->orders->cancel(
            $order,
            $actor,
            $request->note() ?? __('Cancelled by the seller.'),
        ), __('Order cancelled. The buyer will be refunded.'));
    }

    /**
     * The picking list. No prices on it: a picker needs a shelf and a count.
     */
    public function packingSlip(Request $request, Order $order): StreamedResponse
    {
        Gate::authorize('downloadPackingSlip', $order);

        $pdf = $this->documents->packingSlip($order);

        return response()->streamDownload(
            static function () use ($pdf): void {
                echo $pdf;
            },
            $this->documents->filename($order, 'packing-slip'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Authorise, act, and turn a refused transition into something readable.
     *
     * @param  callable(OrderActor): Order  $action
     */
    private function move(Request $request, Order $order, callable $action, string $message): RedirectResponse
    {
        Gate::authorize('fulfil', $order);

        try {
            $action(OrderActor::forUser($this->currentUser($request), $order));
        } catch (InvalidOrderTransition|UnauthorisedOrderAction $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * How many orders sit in each state, for the inbox tabs.
     *
     * @return array<string, int>
     */
    private function countsByStatus(int $sellerId): array
    {
        /** @var array<string, int> $counts */
        $counts = Order::query()
            ->where('seller_id', $sellerId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return $counts;
    }
}
