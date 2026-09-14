<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Http\Resources\MessageThreadResource;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Orders\Models\OrderDispute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The conversation behind a disputed order, for the moderator deciding it.
 *
 * Reached through the DISPUTE, not through the thread list, and that is the
 * whole design. There is no staff route that lists conversations, and no way
 * to ask for one by id: the only door is a dispute, and the policy re-checks
 * that a dispute exists on the thread's order rather than trusting the route
 * it was reached by.
 *
 * Read-only. A moderator's view goes in the resolution note, where both sides
 * see the same words and an audit row records them; staff able to post as a
 * participant could manufacture the evidence they are weighing.
 */
class DisputeThreadController extends Controller
{
    public function show(Request $request, OrderDispute $dispute): Response
    {
        Gate::authorize('moderate');

        $dispute->load('order.buyer', 'order.seller');

        $thread = MessageThread::query()
            ->where('subject_type', $dispute->order->getMorphClass())
            ->where('subject_id', $dispute->order->getKey())
            ->with(['participants.user.seller', 'messages.author.seller', 'messages.media', 'subject'])
            ->first();

        /*
         * The policy is asked even though the route already implies the
         * dispute. Authorisation that lives in a route is authorisation that
         * moves when somebody adds a second route.
         */
        if ($thread !== null) {
            Gate::authorize('view', $thread);
        }

        return Inertia::render('admin/disputes/Thread', [
            'dispute' => [
                'id' => $dispute->getKey(),
                'order_number' => $dispute->order->number,
                'status' => $dispute->status->value,
                'reason' => $dispute->reason->label(),
            ],
            'thread' => $thread === null
                ? null
                : MessageThreadResource::make($thread)->resolve($request),
        ]);
    }
}
