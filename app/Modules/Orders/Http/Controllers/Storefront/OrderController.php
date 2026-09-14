<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Enums\DisputeReason;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Exceptions\UnauthorisedOrderAction;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderDocumentService;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use App\Modules\Ratings\Services\RatingEligibility;
use App\Modules\Ratings\Support\RatingPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The buyer's own orders.
 *
 * One page per seller's order rather than per payment, because that is the
 * unit that has a status, a seller to chase and a part to collect. The
 * payment that produced four of them is a line at the top, not the subject of
 * the page.
 *
 * "Confirm receipt" is the only button here that moves money, and it is the
 * buyer's alone — see OrderStatus::actorsAllowedToEnter(). The controller
 * does not enforce that itself; it hands the state machine an actor worked
 * out from the order and lets the guard refuse.
 */
class OrderController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly OrderDocumentService $documents,
        private readonly RatingEligibility $ratings,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
        ]);

        $status = OrderStatus::tryFrom($filters['status'] ?? '');

        $orders = Order::query()
            ->forBuyer($user)
            ->withStatus($status)
            ->with(['items', 'seller.city', 'disputes'])
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Order $order): array => OrderResource::make($order)->resolve($request));

        return Inertia::render('storefront/orders/Index', [
            'orders' => $orders,
            'filters' => ['status' => $status?->value],
            'statuses' => OrderStatus::options(),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        Gate::authorize('view', $order);

        $order->load(['items', 'seller.city', 'seller.province', 'statusEvents.actor', 'disputes.media', 'group']);

        return Inertia::render('storefront/orders/Show', [
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
            'disputeReasons' => DisputeReason::options(),
            /* Null in every environment without a key; the page falls back to a directions link. */
            'mapsApiKey' => config('services.google_maps.browser_key'),
        ]);
    }

    /**
     * "I have it and it is fine." Releases the escrow.
     */
    public function confirmReceipt(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize('act', $order);

        try {
            $this->orders->complete(
                $order,
                OrderActor::forUser($this->currentUser($request), $order),
                __('Confirmed by the buyer.'),
            );
        } catch (InvalidOrderTransition|UnauthorisedOrderAction $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Thanks. The seller has been paid.'),
        ]);
    }

    /**
     * The buyer's receipt. Streamed rather than stored: it is derived from
     * the order and there is nothing to keep in sync.
     */
    public function receipt(Request $request, Order $order): StreamedResponse
    {
        Gate::authorize('downloadReceipt', $order);

        $pdf = $this->documents->receipt($order);

        return response()->streamDownload(
            static function () use ($pdf): void {
                echo $pdf;
            },
            $this->documents->filename($order, 'receipt'),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
