<script setup lang="ts">
import { Bot } from '@lucide/vue';
import type { OrderTimelineEntry } from '@/types';

/**
 * What happened to this order, in the order it happened.
 *
 * Every entry names who did it, and "MonaFind" against an automatic move is
 * deliberate rather than a placeholder: a great many entries here have no
 * person behind them — a payment landing, a confirmation window closing — and
 * a blank would read as missing data rather than as what actually occurred.
 *
 * This is also the screen a dispute is argued over, so the reason a party
 * gave is shown in their own words underneath.
 */
defineProps<{ entries: OrderTimelineEntry[] }>();

const when = (iso: string): string =>
    new Date(iso).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
</script>

<template>
    <ol class="relative space-y-4 border-l pl-6">
        <li
            v-for="(entry, index) in entries"
            :key="`${entry.status}-${entry.at}`"
            class="relative"
        >
            <span
                class="bg-background absolute -left-[1.9rem] mt-1 flex size-4 items-center justify-center rounded-full border"
                :class="
                    index === entries.length - 1
                        ? 'border-primary bg-primary'
                        : ''
                "
                aria-hidden="true"
            />

            <p class="text-sm font-medium">{{ entry.headline }}</p>

            <p
                class="text-muted-foreground flex flex-wrap items-center gap-1 text-xs"
            >
                <Bot
                    v-if="entry.actor_type === 'system'"
                    class="size-3"
                    aria-hidden="true"
                />
                <span>{{ entry.actor }}</span>
                <span aria-hidden="true">·</span>
                <time :datetime="entry.at">{{ when(entry.at) }}</time>
            </p>

            <p
                v-if="entry.reason"
                class="text-muted-foreground mt-1 text-xs italic"
            >
                {{ entry.reason }}
            </p>
        </li>
    </ol>
</template>
