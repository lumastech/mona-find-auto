<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Eye } from '@lucide/vue';
import ThreadPanel from '@/components/messaging/ThreadPanel.vue';
import admin from '@/routes/admin';
import type { MessageThread } from '@/types/messaging';

/**
 * The conversation behind a disputed order.
 *
 * The only staff view of a private thread on the platform, and read-only. A
 * moderator's say goes in the resolution note, where both parties see the
 * same words and an audit row records them — staff able to post as a
 * participant could manufacture the evidence they are weighing.
 */
defineProps<{
    dispute: {
        id: number;
        order_number: string;
        status: string;
        reason: string;
    };
    thread: MessageThread | null;
}>();
</script>

<template>
    <Head :title="`Dispute ${dispute.order_number}: conversation`" />

    <div class="flex h-[calc(100vh-10rem)] flex-col gap-3">
        <Link
            :href="admin.disputes.index()"
            class="text-muted-foreground hover:text-foreground flex items-center gap-1.5 text-sm"
        >
            <ArrowLeft class="size-4" />
            All disputes
        </Link>

        <header class="space-y-1">
            <h1 class="text-lg font-semibold tracking-tight">
                Order {{ dispute.order_number }} — conversation
            </h1>
            <p class="text-muted-foreground flex items-center gap-1.5 text-sm">
                <Eye class="size-3.5" />
                Read only. {{ dispute.reason }}.
            </p>
        </header>

        <ThreadPanel
            v-if="thread"
            :thread="thread"
            :can-reply="false"
            class="min-h-0 flex-1"
        />

        <p
            v-else
            class="text-muted-foreground rounded-lg border px-4 py-16 text-center text-sm"
        >
            The buyer and the seller never used MonaFind messages for this
            order, so there is no conversation to read.
        </p>
    </div>
</template>
