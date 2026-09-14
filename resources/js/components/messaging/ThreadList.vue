<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FileText, MessageSquare, Package, Store } from '@lucide/vue';
import type { MessageThread } from '@/types/messaging';

/**
 * An inbox.
 *
 * Shared by the storefront and the seller portal, which differ only in where
 * a row points — a shop opens its own view of the same conversation, inside
 * its own shell. Hence `hrefFor` as a prop rather than a route baked in.
 */
defineProps<{
    threads: MessageThread[];
    hrefFor: (thread: MessageThread) => string;
    emptyMessage?: string;
}>();

const iconFor = (subjectType: string) => {
    switch (subjectType) {
        case 'Order':
            return Package;
        case 'Quotation':
            return FileText;
        case 'Seller':
            return Store;
        default:
            return MessageSquare;
    }
};

const relative = (iso: string | null): string => {
    if (iso === null) {
        return '';
    }

    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);

    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m ago`;
    if (minutes < 1440) return `${Math.round(minutes / 60)}h ago`;

    return new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
};
</script>

<template>
    <ul v-if="threads.length" class="divide-y rounded-lg border">
        <li v-for="thread in threads" :key="thread.id">
            <Link
                :href="hrefFor(thread)"
                class="hover:bg-accent flex items-start gap-3 px-4 py-3"
            >
                <component
                    :is="iconFor(thread.subject_type)"
                    class="text-muted-foreground mt-0.5 size-5 shrink-0"
                />

                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-2">
                        <p
                            class="truncate text-sm"
                            :class="
                                thread.is_unread
                                    ? 'font-semibold'
                                    : 'font-medium'
                            "
                        >
                            {{ thread.counterpart?.name ?? 'MonaFind' }}
                        </p>
                        <span
                            class="text-muted-foreground shrink-0 text-xs whitespace-nowrap"
                        >
                            {{ relative(thread.last_message_at) }}
                        </span>
                    </div>

                    <p class="text-muted-foreground truncate text-xs">
                        {{ thread.subject_label }}
                    </p>
                </div>

                <span
                    v-if="thread.is_unread"
                    class="bg-primary mt-2 size-2 shrink-0 rounded-full"
                    aria-label="Unread"
                />
            </Link>
        </li>
    </ul>

    <p
        v-else
        class="text-muted-foreground rounded-lg border px-4 py-12 text-center text-sm"
    >
        {{ emptyMessage ?? 'No conversations yet.' }}
    </p>
</template>
