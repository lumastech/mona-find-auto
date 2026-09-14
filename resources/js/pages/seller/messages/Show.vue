<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import ThreadPanel from '@/components/messaging/ThreadPanel.vue';
import seller from '@/routes/seller';
import type { MessageThread } from '@/types/messaging';

/**
 * One conversation, from the shop's side.
 *
 * The same panel the buyer reads, inside the seller shell. Replying posts to
 * the storefront route, which is where the one implementation of "may this
 * person write here" lives.
 */
defineProps<{ thread: MessageThread }>();
</script>

<template>
    <Head :title="`Messages: ${thread.subject_label}`" />

    <div class="flex h-[calc(100vh-10rem)] flex-col gap-3">
        <Link
            :href="seller.messages.index()"
            class="text-muted-foreground hover:text-foreground flex items-center gap-1.5 text-sm"
        >
            <ArrowLeft class="size-4" />
            All messages
        </Link>

        <ThreadPanel :thread="thread" class="min-h-0 flex-1" />
    </div>
</template>
