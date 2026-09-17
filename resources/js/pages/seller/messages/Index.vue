<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ThreadList from '@/components/messaging/ThreadList.vue';
import seller from '@/routes/seller';
import type { MessageThread } from '@/types/messaging';

/**
 * The shop's inbox.
 *
 * Scoped to the BUSINESS, not to whoever is signed in: a shop with counter
 * staff has several accounts that may answer, and a buyer who wrote on
 * Tuesday should not have to wait for the particular person who was on that
 * day. That is the whole difference between this page and the storefront one.
 */
defineProps<{
    threads: {
        data: MessageThread[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    unreadCount: number;
}>();

const hrefFor = (thread: MessageThread): string =>
    seller.messages.show.url({ thread: thread.id });
</script>

<template>
    <Head title="Messages" />

    <div class="space-y-6 px-4 sm:px-6 lg:px-8">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Messages</h1>
            <p class="text-muted-foreground text-sm">
                Every conversation about your shop, whoever on your side is
                signed in.
                <span
                    v-if="unreadCount > 0"
                    class="text-foreground font-medium"
                >
                    {{ unreadCount }} waiting for a reply.
                </span>
            </p>
        </header>

        <ThreadList
            :threads="threads.data"
            :href-for="hrefFor"
            empty-message="No conversations yet. Buyers can message you from any of your listings."
        />
    </div>
</template>
