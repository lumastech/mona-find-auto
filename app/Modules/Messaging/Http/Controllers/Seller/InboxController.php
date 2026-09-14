<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Http\Resources\MessageThreadResource;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\ThreadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop's inbox.
 *
 * Scoped to the SHOP rather than to the person reading it, which is the
 * difference between this and the storefront inbox. A shop with counter staff
 * has several accounts that may answer, and a buyer who wrote on Tuesday
 * should not have to wait for the particular person who was on the counter
 * then — so the listing is every thread carrying this seller's id, whoever on
 * the shop's side is signed in.
 *
 * Reading and replying still go through the storefront thread view and its
 * policy, which asks about participants. Staff who are not participants can
 * see that a conversation exists and open the shop's side of it, but the
 * policy is what decides; this controller does not grant anything.
 */
class InboxController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly ThreadService $threads) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentUser($request)->seller;

        abort_if($seller === null, 403);

        $threads = MessageThread::query()
            ->forSeller($seller)
            ->with(['participants.user.seller', 'subject'])
            ->recentFirst()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MessageThread $thread): array => MessageThreadResource::make($thread)->resolve($request));

        return Inertia::render('seller/messages/Index', [
            'threads' => $threads,
            'unreadCount' => $this->threads->unreadCountFor($this->currentUser($request)),
        ]);
    }

    public function show(Request $request, MessageThread $thread): Response
    {
        Gate::authorize('view', $thread);

        $user = $this->currentUser($request);

        $thread->load(['participants.user.seller', 'messages.author.seller', 'messages.media', 'subject']);

        $this->threads->markRead($thread, $user);

        return Inertia::render('seller/messages/Show', [
            'thread' => MessageThreadResource::make($thread)->resolve($request),
        ]);
    }
}
