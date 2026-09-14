<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink, ShieldCheck } from '@lucide/vue';
import { nextTick, onMounted, ref, watch } from 'vue';
import MessageBubble from '@/components/messaging/MessageBubble.vue';
import MessageComposer from '@/components/messaging/MessageComposer.vue';
import type { MessageThread } from '@/types/messaging';

/**
 * One conversation, whole: header, messages, composer.
 *
 * The same component in the storefront, the seller portal and — with the
 * composer suppressed — the staff dispute view, because a conversation read
 * three different ways is three chances for the three to disagree about what
 * was said.
 *
 * It scrolls to the newest message rather than the oldest. A thread is opened
 * to see what has just arrived, not to re-read it from the beginning.
 */
const props = withDefaults(
    defineProps<{
        thread: MessageThread;
        /** False on the staff dispute view: read-only, never a participant. */
        canReply?: boolean;
    }>(),
    { canReply: true },
);

const scroller = ref<HTMLElement | null>(null);

const toBottom = async (): Promise<void> => {
    await nextTick();

    if (scroller.value) {
        scroller.value.scrollTop = scroller.value.scrollHeight;
    }
};

onMounted(toBottom);
watch(() => props.thread.messages?.length, toBottom);
</script>

<template>
    <div class="bg-background flex h-full min-h-0 flex-col rounded-lg border">
        <header
            class="flex items-start justify-between gap-3 border-b px-4 py-3"
        >
            <div class="min-w-0">
                <h2 class="truncate text-sm font-semibold">
                    {{ thread.counterpart?.name ?? 'MonaFind' }}
                </h2>
                <p class="text-muted-foreground truncate text-xs">
                    {{ thread.counterpart?.role_label }} ·
                    {{ thread.subject_label }}
                </p>
            </div>

            <Link
                v-if="thread.subject_url"
                :href="thread.subject_url"
                class="text-muted-foreground hover:text-foreground flex shrink-0 items-center gap-1 text-xs"
            >
                <ExternalLink class="size-3.5" />
                Open
            </Link>
        </header>

        <!--
            Stated once, at the top, rather than on every bubble: after payment
            the two sides are entitled to each other's details, and knowing
            that is what stops the conversation moving to WhatsApp.
        -->
        <p
            v-if="thread.allows_contact_details"
            class="text-muted-foreground flex items-center gap-1.5 border-b px-4 py-2 text-xs"
        >
            <ShieldCheck class="size-3.5 shrink-0" />
            This order is paid, so you can share phone numbers and addresses
            here.
        </p>

        <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto p-3 sm:p-4">
            <ul class="space-y-3">
                <MessageBubble
                    v-for="message in thread.messages ?? []"
                    :key="message.id"
                    :message="message"
                />
            </ul>

            <p
                v-if="(thread.messages ?? []).length === 0"
                class="text-muted-foreground py-12 text-center text-sm"
            >
                No messages yet.
            </p>
        </div>

        <MessageComposer v-if="canReply" :thread="thread" />
    </div>
</template>
