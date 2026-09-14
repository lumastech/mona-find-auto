<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import ThreadPanel from '@/components/messaging/ThreadPanel.vue';
import threadRoutes from '@/routes/threads';
import type { MessageThread } from '@/types/messaging';

/**
 * One conversation.
 *
 * Sized to fill the viewport rather than to grow with the content, so that on
 * a phone the composer stays put and the messages scroll under it — a thread
 * where the box you type in walks off the bottom of the screen is a thread
 * nobody answers from a phone.
 */
defineProps<{ thread: MessageThread }>();
</script>

<template>
    <Head :title="`Messages: ${thread.subject_label}`" />

    <div
        class="mx-auto flex h-[calc(100vh-9rem)] w-full max-w-3xl flex-col gap-3"
    >
        <Link
            :href="threadRoutes.index()"
            class="text-muted-foreground hover:text-foreground flex items-center gap-1.5 text-sm"
        >
            <ArrowLeft class="size-4" />
            All messages
        </Link>

        <ThreadPanel :thread="thread" class="min-h-0 flex-1" />
    </div>
</template>
