<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutUnavailable;
use App\Modules\Orders\Exceptions\DisputeNotAllowed;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Exceptions\UnauthorisedOrderAction;
use App\Modules\Orders\Http\Requests\Storefront\OpenDisputeRequest;
use App\Modules\Orders\Http\Requests\Storefront\PlaceOrderRequest;
use App\Modules\Orders\Http\Resources\CheckoutResource;
use App\Modules\Orders\Http\Resources\OrderGroupResource;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Services\DisputeService;
use App\Modules\Orders\Services\OrderDocumentService;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The buyer's orders, for the mobile app.
 *
 * The same services the web area uses, so there is exactly one implementation
 * of what checkout validates, what a transition allows and who may dispute —
 * the API is a second front door, not a second rulebook.
 *
 * Orders are addressed by number rather than id, because that is what a buyer
 * sees on their receipt and reads out in a shop; an id would make the app's
 * URLs and the buyer's paperwork disagree.
 */
class OrderController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly OrderStateMachine $orders,
        private readonly DisputeService $disputes,
        private readonly OrderDocumentService $documents,
    ) {}

    /**
     * The buyer's receipt as a PDF.
     *
     * A file rather than JSON, and the one endpoint in this controller that
     * does not answer in the envelope — a receipt is a document somebody
     * saves, forwards to an employer or takes to a shop counter, and wrapping
     * the bytes in a JSON envelope would make it none of those things.
     *
     * The same policy gate as the web route: a receipt is a record of
     * somebody's spending.
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

    /**
     * The checkout as it stands: shops, options, fees and full policy text.
     */
    public function checkout(Request $request): JsonResponse
    {
        $view = $this->checkout->view($this->currentUser($request));

        return ApiResponse::ok(CheckoutResource::make($view)->resolve($request));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string'],
        ]);

        $orders = Order::query()
            ->forBuyer($this->currentUser($request))
            ->withStatus(OrderStatus::tryFrom($filters['status'] ?? ''))
            ->with(['items', 'seller', 'disputes'])
            ->paginate((int) $request->integer('per_page', 15))
            ->through(fn (Order $order): array => OrderResource::make($order)->resolve($request));

        return ApiResponse::paginated($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        $order->load(['items', 'seller', 'statusEvents.actor', 'disputes.media']);

        return ApiResponse::ok(OrderResource::make($order)->resolve($request));
    }

    /**
     * Place the order. Returns the group, which is what payment is addressed
     * to, with every seller's order inside it.
     */
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        try {
            $group = $this->checkout->place(
                $this->currentUser($request),
                $request->selections(),
                $request->paymentMethod(),
                $request,
            );
        } catch (CheckoutUnavailable $exception) {
            return ApiResponse::error(
                'checkout_unavailable',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        $group->load(['orders.items', 'orders.seller']);

        return ApiResponse::created(OrderGroupResource::make($group)->resolve($request));
    }

    public function confirmReceipt(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('act', $order);

        try {
            $this->orders->complete(
                $order,
                OrderActor::forUser($this->currentUser($request), $order),
                __('Confirmed by the buyer.'),
            );
        } catch (InvalidOrderTransition|UnauthorisedOrderAction $exception) {
            return ApiResponse::error(
                'invalid_transition',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        $order->refresh()->load(['items', 'seller', 'statusEvents.actor']);

        return ApiResponse::ok(OrderResource::make($order)->resolve($request));
    }

    public function dispute(OpenDisputeRequest $request, Order $order): JsonResponse
    {
        Gate::authorize('act', $order);

        try {
            $this->disputes->open(
                $order,
                $this->currentUser($request),
                $request->reason(),
                (string) $request->validated('details'),
                $request->photos(),
            );
        } catch (DisputeNotAllowed $exception) {
            return ApiResponse::error(
                'dispute_not_allowed',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        $order->refresh()->load(['items', 'seller', 'statusEvents.actor', 'disputes.media']);

        return ApiResponse::ok(OrderResource::make($order)->resolve($request));
    }
}
