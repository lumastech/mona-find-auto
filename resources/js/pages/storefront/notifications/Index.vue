<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BellOff, Check, Settings2, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import notificationRoutes from '@/routes/notifications';
import settings from '@/routes/settings';
import type { AppNotification } from '@/types/messaging';

/**
 * Everything the platform has told this person.
 *
 * The list renders without knowing what kind each notification is: every one
 * writes a title, a body and an optional action, so a notification added
 * after this page was written still draws. That is why there is no switch on
 * `event` here — reading into `data` would be the thing that makes adding a
 * notification a frontend change as well as a backend one.
 */
defineProps<{
    notifications: {
        data: AppNotification[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    unreadCount: number;
    filters: { unread: boolean };
}>();

const markRead = (notification: AppNotification): void => {
    if (notification.read_at !== null) {
        return;
    }

    router.post(
        notificationRoutes.read.url({ notification: notification.id }),
        {},
        { preserveScroll: true },
    );
};

const markAllRead = (): void => {
    router.post(notificationRoutes.readAll.url(), {}, { preserveScroll: true });
};

const remove = (notification: AppNotification): void => {
    router.delete(
        notificationRoutes.destroy.url({ notification: notification.id }),
        { preserveScroll: true },
    );
};

const when = (iso: string | null): string =>
    iso === null
        ? ''
        : new Date(iso).toLocaleString('en-GB', {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          });
</script>

<template>
    <Head title="Notifications" />

    <div class="mx-auto w-full max-w-3xl space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-xl font-semibold tracking-tight">
                    Notifications
                </h1>
                <p class="text-muted-foreground text-sm">
                    <template v-if="unreadCount > 0">
                        {{ unreadCount }} unread.
                    </template>
                    <template v-else>You are up to date.</template>
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="unreadCount > 0"
                    variant="outline"
                    size="sm"
                    class="gap-1.5"
                    @click="markAllRead"
                >
                    <Check class="size-4" />
                    Mark all read
                </Button>

                <Button variant="outline" size="sm" as-child class="gap-1.5">
                    <Link :href="settings.notifications.edit()">
                        <Settings2 class="size-4" />
                        Preferences
                    </Link>
                </Button>
            </div>
        </header>

        <nav class="flex gap-2 text-sm" aria-label="Filter notifications">
            <Link
                :href="notificationRoutes.index()"
                class="rounded-full px-3 py-1"
                :class="
                    filters.unread
                        ? 'text-muted-foreground hover:bg-accent'
                        : 'bg-primary text-primary-foreground'
                "
            >
                All
            </Link>
            <Link
                :href="notificationRoutes.index({ query: { unread: 1 } })"
                class="rounded-full px-3 py-1"
                :class="
                    filters.unread
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-accent'
                "
            >
                Unread
            </Link>
        </nav>

        <ul v-if="notifications.data.length" class="divide-y rounded-lg border">
            <li
                v-for="notification in notifications.data"
                :key="notification.id"
                class="flex items-start gap-3 px-4 py-3"
                :class="notification.read_at === null ? 'bg-accent/40' : ''"
            >
                <span
                    class="mt-2 size-2 shrink-0 rounded-full"
                    :class="
                        notification.read_at === null
                            ? 'bg-primary'
                            : 'bg-transparent'
                    "
                    :aria-label="
                        notification.read_at === null ? 'Unread' : undefined
                    "
                />

                <div class="min-w-0 flex-1">
                    <component
                        :is="notification.action_url ? 'a' : 'div'"
                        :href="notification.action_url ?? undefined"
                        class="block"
                        @click="markRead(notification)"
                    >
                        <p class="text-sm font-medium">
                            {{ notification.title }}
                        </p>
                        <p
                            v-if="notification.body"
                            class="text-muted-foreground mt-0.5 text-sm"
                        >
                            {{ notification.body }}
                        </p>
                    </component>

                    <div
                        class="text-muted-foreground mt-1.5 flex flex-wrap items-center gap-3 text-xs"
                    >
                        <span>{{ when(notification.created_at) }}</span>
                        <a
                            v-if="notification.action_url"
                            :href="notification.action_url"
                            class="underline underline-offset-2"
                            @click="markRead(notification)"
                        >
                            {{ notification.action_label ?? 'Open' }}
                        </a>
                    </div>
                </div>

                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground shrink-0 p-1"
                    aria-label="Delete this notification"
                    @click="remove(notification)"
                >
                    <Trash2 class="size-4" />
                </button>
            </li>
        </ul>

        <div
            v-else
            class="text-muted-foreground flex flex-col items-center gap-2 rounded-lg border px-4 py-16 text-center text-sm"
        >
            <BellOff class="size-6" />
            <p>
                {{
                    filters.unread
                        ? 'Nothing unread.'
                        : 'Nothing here yet. Orders, quotes and messages will show up on this page.'
                }}
            </p>
        </div>
    </div>
</template>
