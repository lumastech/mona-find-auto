<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\NotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bell, and the page behind it.
 *
 * One controller for all three areas. A notification is not storefront or
 * seller or staff — a shop owner gets an order notification and a payout
 * notification and a review notification, and routing them to three different
 * inboxes by which part of the platform produced them would mean checking
 * three. The bell in every header points here.
 */
class NotificationController extends Controller
{
    use InteractsWithCurrentUser;

    /** How many the dropdown shows before "see all". */
    private const DROPDOWN_LIMIT = 10;

    public function __construct(private readonly NotificationFeed $feed) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);
        $unreadOnly = $request->boolean('unread');

        return Inertia::render('storefront/notifications/Index', [
            'notifications' => $this->feed->paginate($user, unreadOnly: $unreadOnly)->withQueryString(),
            'unreadCount' => $this->feed->unreadCount($user),
            'filters' => ['unread' => $unreadOnly],
        ]);
    }

    /**
     * The dropdown's contents, as plain JSON.
     *
     * A small endpoint of its own rather than a shared prop, because the bell
     * is in every header on the platform: sending ten notifications with every
     * page load would be ten rows of JSON on every request, for a panel most
     * people open once a day — and on a low-end Android over a slow connection
     * that is a real cost. The BADGE is a shared prop, because it is one
     * integer and has to be right everywhere; the LIST is fetched on first
     * open.
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return response()->json([
            'notifications' => $this->feed
                ->paginate($user, perPage: self::DROPDOWN_LIMIT, unreadOnly: true)
                ->items(),
            'unread_count' => $this->feed->unreadCount($user),
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $this->feed->markRead($this->currentUser($request), $notification);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->feed->markAllRead($this->currentUser($request));

        return back();
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->feed->delete($this->currentUser($request), $notification);

        return back();
    }
}
