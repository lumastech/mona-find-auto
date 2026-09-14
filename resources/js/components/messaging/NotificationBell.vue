<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, Check, MessageSquare } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import notifications from '@/routes/notifications';
import threads from '@/routes/threads';
import type { AppNotification } from '@/types/messaging';

/**
 * The bell, in all three headers.
 *
 * Its count comes from the shared `messaging` prop rather than from each page,
 * for the same reason the cart badge does: a number that is only right on the
 * pages that remembered to send it is a number nobody believes.
 *
 * The dropdown loads its contents on first open rather than with every page.
 * A header that fetched ten notifications on every request would be ten rows
 * of JSON on every page load of the whole platform, for a panel most people
 * open once a day — and on a low-end Android over a slow connection that is a
 * real cost. The badge is a single integer and is always there.
 *
 * Guests get nothing at all: `messaging` is null for them, and rendering an
 * empty bell would invite a click that leads to a login screen.
 */
const page = usePage();

const counts = computed(() => page.props.messaging);

const unreadNotifications = computed(
    () => counts.value?.unread_notifications ?? 0,
);
const unreadThreads = computed(() => counts.value?.unread_threads ?? 0);

const total = computed(() => unreadNotifications.value + unreadThreads.value);

const items = ref<AppNotification[] | null>(null);
const loading = ref(false);

/** A number nobody wants to read. */
const badge = computed(() => (total.value > 9 ? '9+' : String(total.value)));

const load = async (open: boolean): Promise<void> => {
    if (!open || items.value !== null || loading.value) {
        return;
    }

    loading.value = true;

    try {
        const response = await fetch(notifications.recent.url(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            items.value = [];

            return;
        }

        const payload = await response.json();

        items.value = (payload?.notifications ?? []) as AppNotification[];
    } catch {
        /* An unreachable bell is an empty bell, never a broken page. */
        items.value = [];
    } finally {
        loading.value = false;
    }
};

const markAllRead = (): void => {
    router.post(
        notifications.readAll.url(),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                items.value = [];
            },
        },
    );
};

const relative = (iso: string | null): string => {
    if (iso === null) {
        return '';
    }

    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);

    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m ago`;
    if (minutes < 1440) return `${Math.round(minutes / 60)}h ago`;

    return `${Math.round(minutes / 1440)}d ago`;
};
</script>

<template>
    <DropdownMenu v-if="counts" @update:open="load">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="
                    total > 0
                        ? `Notifications, ${total} unread`
                        : 'Notifications'
                "
            >
                <Bell class="size-5" />
                <Badge
                    v-if="total > 0"
                    variant="destructive"
                    class="absolute -top-1 -right-1 h-5 min-w-5 justify-center rounded-full px-1 text-[10px] tabular-nums"
                >
                    {{ badge }}
                </Badge>
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-80 p-0">
            <div class="flex items-center justify-between border-b px-3 py-2">
                <p class="text-sm font-medium">Notifications</p>
                <Button
                    v-if="unreadNotifications > 0"
                    variant="ghost"
                    size="sm"
                    class="h-7 gap-1 text-xs"
                    @click="markAllRead"
                >
                    <Check class="size-3.5" />
                    Mark all read
                </Button>
            </div>

            <!-- Unread conversations are a separate line: they are answered, not dismissed. -->
            <Link
                v-if="unreadThreads > 0"
                :href="threads.index()"
                class="hover:bg-accent flex items-center gap-2 border-b px-3 py-2.5 text-sm"
            >
                <MessageSquare class="text-muted-foreground size-4 shrink-0" />
                <span>
                    {{ unreadThreads }}
                    {{ unreadThreads === 1 ? 'conversation' : 'conversations' }}
                    waiting for you
                </span>
            </Link>

            <div class="max-h-80 overflow-y-auto">
                <p
                    v-if="loading"
                    class="text-muted-foreground px-3 py-6 text-center text-sm"
                >
                    Loading…
                </p>

                <p
                    v-else-if="items !== null && items.length === 0"
                    class="text-muted-foreground px-3 py-6 text-center text-sm"
                >
                    Nothing new.
                </p>

                <component
                    :is="notification.action_url ? 'a' : 'div'"
                    v-for="notification in items ?? []"
                    :key="notification.id"
                    :href="notification.action_url ?? undefined"
                    class="hover:bg-accent block border-b px-3 py-2.5 last:border-b-0"
                >
                    <p class="text-sm font-medium">{{ notification.title }}</p>
                    <p
                        v-if="notification.body"
                        class="text-muted-foreground mt-0.5 line-clamp-2 text-xs"
                    >
                        {{ notification.body }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-[11px]">
                        {{ relative(notification.created_at) }}
                    </p>
                </component>
            </div>

            <Link
                :href="notifications.index()"
                class="hover:bg-accent block border-t px-3 py-2 text-center text-sm font-medium"
            >
                See all notifications
            </Link>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
