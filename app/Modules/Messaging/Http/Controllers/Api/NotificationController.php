<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Http\Requests\Storefront\NotificationPreferenceRequest;
use App\Modules\Messaging\Services\NotificationFeed;
use App\Modules\Messaging\Services\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * /api/v1/notifications — the mobile app's bell.
 *
 * The same feed the web bell reads, in the same shape, because the mobile app
 * has to render a notification it has never heard of: a notification added to
 * the platform after an app release still arrives with a title, a body and an
 * action, and still draws.
 *
 * Preferences live here too rather than under a separate resource. A person
 * turning off texts does it from their phone more often than from a desktop,
 * and the screen that shows them what they will receive is the same screen
 * that changes it.
 */
class NotificationController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly NotificationFeed $feed,
        private readonly NotificationPreferenceService $preferences,
    ) {}

    /**
     * The caller's notifications, newest first. `?unread=1` for the badge.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $notifications = $this->feed->paginate(
            $user,
            perPage: min(50, max(1, (int) $request->integer('per_page', 20))),
            unreadOnly: $request->boolean('unread'),
        );

        return ApiResponse::paginated($notifications, [
            'unread_count' => $this->feed->unreadCount($user),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $this->feed->markRead($user, $notification)) {
            return ApiResponse::error(
                'notification_not_found',
                'That notification does not exist on this account.',
                status: HttpResponse::HTTP_NOT_FOUND,
            );
        }

        return ApiResponse::ok(['unread_count' => $this->feed->unreadCount($user)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return ApiResponse::ok([
            'marked' => $this->feed->markAllRead($user),
            'unread_count' => $this->feed->unreadCount($user),
        ]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $this->feed->delete($this->currentUser($request), $notification);

        return ApiResponse::noContent();
    }

    /**
     * What this person will and will not be told about, grouped as the
     * preference screen shows it.
     */
    public function preferences(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->preferences->screenFor($this->currentUser($request)));
    }

    public function updatePreferences(NotificationPreferenceRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $this->preferences->update($user, $request->validated('preferences'));

        return ApiResponse::ok($this->preferences->screenFor($user));
    }
}
