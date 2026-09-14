<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app bell, and the page behind it.
 *
 * Laravel's `notifications` table is the store; this is the reading of it.
 * The one thing it adds is a stable shape: every notification's `toArray()`
 * on this platform writes a `title`, a `body` and an optional action, so the
 * bell can render anything without knowing what kind of thing it is — and the
 * handful of older notifications that wrote something else still render,
 * because `present()` fills the gaps rather than assuming.
 */
class NotificationFeed
{
    /**
     * One page of somebody's notifications, newest first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $user, int $perPage = 20, bool $unreadOnly = false): LengthAwarePaginator
    {
        return $user->notifications()
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->paginate($perPage)
            ->through(fn (DatabaseNotification $notification): array => $this->present($notification));
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * Mark one as read. Returns false when it is not this person's to mark —
     * a notification id is a UUID, but guessing is not the reason to check.
     */
    public function markRead(User $user, string $id): bool
    {
        $notification = $user->notifications()->whereKey($id)->first();

        if ($notification === null) {
            return false;
        }

        $notification->markAsRead();

        return true;
    }

    /**
     * Clear the badge in one move.
     *
     * A single UPDATE rather than a read-then-write per row: somebody coming
     * back from a fortnight away may have hundreds, and "mark all read" that
     * takes a visible moment is a button people press twice.
     */
    public function markAllRead(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }

    public function delete(User $user, string $id): bool
    {
        return $user->notifications()->whereKey($id)->delete() > 0;
    }

    /**
     * One notification, in the shape the bell renders.
     *
     * @return array<string, mixed>
     */
    public function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => $notification->getKey(),
            'event' => $data['type'] ?? null,
            'title' => $data['title'] ?? $this->fallbackTitle($data),
            'body' => $data['body'] ?? $data['message'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'action_label' => $data['action_label'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'data' => $data,
        ];
    }

    /**
     * Something readable for a notification written before the shape settled.
     *
     * @param  array<string, mixed>  $data
     */
    private function fallbackTitle(array $data): string
    {
        foreach (['headline', 'subject', 'type'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return 'MonaFind';
    }
}
