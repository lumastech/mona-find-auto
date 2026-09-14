<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff searching orders.
 *
 * Read-only. Everything staff can actually DO to an order — resolving a
 * dispute, releasing money — happens through the dispute queue or, later,
 * through Finance, both of which write reasons and audit rows. A general
 * "edit this order" screen in the console would be the one place on the
 * platform where an order's history could be quietly rearranged.
 */
class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'seller_id' => ['nullable', 'integer'],
            'awaiting_confirmation' => ['nullable', 'boolean'],
        ]);

        $status = OrderStatus::tryFrom($filters['status'] ?? '');

        $orders = Order::query()
            ->search($filters['search'] ?? null)
            ->withStatus($status)
            ->when(
                isset($filters['seller_id']),
                fn ($query) => $query->where('seller_id', $filters['seller_id']),
            )
            /*
             * The dashboard's "past the confirmation window" tile lands here.
             * A plain status filter would not do it: the queue is paid orders
             * whose deadline has passed, and most paid orders are inside it.
             */
            ->when(
                filter_var($filters['awaiting_confirmation'] ?? false, FILTER_VALIDATE_BOOL),
                fn ($query) => $query->awaitingSellerConfirmation(),
            )
            ->with(['items', 'buyer', 'seller', 'disputes'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Order $order): array => OrderResource::make($order)->resolve($request));

        return Inertia::render('admin/orders/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $filters['search'] ?? null,
                'status' => $status?->value,
                'seller_id' => $filters['seller_id'] ?? null,
                'awaiting_confirmation' => filter_var($filters['awaiting_confirmation'] ?? false, FILTER_VALIDATE_BOOL),
            ],
            'statuses' => OrderStatus::options(),
        ]);
    }

    /**
     * One order in full, including what the buyer accepted.
     *
     * The terms acceptance is loaded here and nowhere else in the console,
     * because this is the screen a moderator opens when a seller quotes their
     * own refund policy at a buyer and somebody has to check which version
     * was actually agreed to.
     */
    public function show(Request $request, Order $order): Response
    {
        $order->load([
            'items',
            'buyer',
            'seller.city',
            'statusEvents.actor',
            'disputes.media',
            'disputes.opener',
            'disputes.resolver',
            'termsAcceptance',
            'group',
        ]);

        $acceptance = $order->termsAcceptance;

        return Inertia::render('admin/orders/Show', [
            'order' => OrderResource::make($order)->resolve($request),
            'money' => [
                'commission_ngwee' => $order->commission()->ngwee,
                'payout_ngwee' => $order->sellerPayout()->ngwee,
                'payment_mode' => $order->payment_mode?->value,
                'monetisation' => $order->monetisation_snapshot,
                'snapshot_at' => $order->snapshot_at?->toIso8601String(),
            ],
            'acceptance' => $acceptance === null ? null : [
                'accepted_at' => $acceptance->accepted_at->toIso8601String(),
                'ip_address' => $acceptance->ip_address,
                'user_agent' => $acceptance->user_agent,
                'platform_terms_version' => $acceptance->platform_terms_version,
                'minimum_refund_days' => $acceptance->minimum_refund_days,
                'minimum_refund_statement' => $acceptance->minimum_refund_statement,
                'policies' => $acceptance->policies,
            ],
        ]);
    }
}
