<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ThreadList from '@/components/messaging/ThreadList.vue';
import threadRoutes from '@/routes/threads';
import type { MessageThread } from '@/types/messaging';

/**
 * The buyer's — and the mechanic's — inbox.
 *
 * One inbox for both, deliberately. A mechanic on MonaFind is a person with a
 * public profile who also buys parts, not a separate kind of account, and a
 * second inbox would mean messages landing in whichever of the two they were
 * not looking at.
 */
defineProps<{
    threads: {
        data: MessageThread[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
}>();

const hrefFor = (thread: MessageThread): string =>
    threadRoutes.show.url({ thread: thread.id });
</script>

<template>
    <Head title="Messages" />

    <div class="mx-auto w-full max-w-3xl space-y-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Messages</h1>
            <p class="text-muted-foreground text-sm">
                Your conversations with shops about listings, quotes and orders.
            </p>
        </header>

        <ThreadList
            :threads="threads.data"
            :href-for="hrefFor"
            empty-message="No conversations yet. Ask a shop a question from any listing and it will appear here."
        />
    </div>
</template>
