<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import type { ConsoleCounter } from '@/types';

/**
 * One queue with work in it, rendered loudly enough to be triaged at a glance.
 *
 * Counters that have come back zero are NOT drawn with this card — they get
 * the compact chip on the dashboard instead. That split is the whole point:
 * a grid where the empty queue and the nine-dispute queue are the same size
 * teaches staff that the size means nothing, and then they stop reading it.
 *
 * Tone is the owning module's judgement (see ConsoleCounterTone), so this
 * component translates it and never second-guesses it — forty listings
 * awaiting moderation is a normal Tuesday and one unresolved reconciliation
 * exception is not.
 */
const props = defineProps<{
    counter: ConsoleCounter;
    /** Position in the grid, used to stagger the entrance. */
    index?: number;
}>();

/**
 * Burnt orange and the danger red come from the brand tokens, never a Tailwind
 * palette colour: an amber warning sitting beside Soft Gold reads as a second
 * trust mark rather than a caution.
 */
const tone = computed(() => {
    switch (props.counter.tone) {
        case 'critical':
            return {
                rail: 'bg-danger',
                value: 'text-danger',
                border: 'border-danger/25 group-hover:border-danger/50',
                wash: 'from-danger/[0.07]',
            };
        case 'warning':
            return {
                rail: 'bg-warning',
                value: 'text-warning',
                border: 'border-warning/25 group-hover:border-warning/50',
                wash: 'from-warning/[0.07]',
            };
        default:
            return {
                rail: 'bg-primary/40',
                value: 'text-foreground',
                border: 'border-border group-hover:border-primary/40',
                wash: 'from-primary/[0.05]',
            };
    }
});

/** Entrances stagger ~60ms apart; past the first handful it just feels slow. */
const delay = computed(() => `${Math.min(props.index ?? 0, 7) * 60}ms`);
</script>

<template>
    <Link
        :href="counter.href"
        :aria-label="`${counter.label}: ${counter.value}`"
        :style="{ animationDelay: delay }"
        class="group focus-visible:ring-ring motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-2 motion-safe:fill-mode-both relative block h-full rounded-xl duration-300 ease-out focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
    >
        <!--
            200ms ease-out: quicker reads as twitchy, slower reads as stuck.
            The card lifts and its shadow stretches; it never scales, because
            scaling a tile in a grid nudges its neighbours.
        -->
        <article
            :class="[
                tone.border,
                'bg-card shadow-elev-2 group-hover:shadow-elev-3 relative flex h-full flex-col overflow-hidden rounded-xl border transition-[box-shadow,border-color,transform] duration-200 ease-out motion-safe:group-hover:-translate-y-1',
            ]"
        >
            <!-- The tone, carried by a rail rather than a whole coloured card. -->
            <span
                :class="[tone.rail, 'absolute inset-y-0 left-0 w-1']"
                aria-hidden="true"
            />

            <!-- Claims the cursor without moving anything. -->
            <span
                :class="[
                    tone.wash,
                    'pointer-events-none absolute inset-0 bg-gradient-to-br to-transparent opacity-0 transition-opacity duration-200 ease-out group-hover:opacity-100',
                ]"
                aria-hidden="true"
            />

            <div class="relative flex flex-1 flex-col gap-4 p-5 pl-6">
                <div class="flex items-start justify-between gap-3">
                    <h3
                        class="text-card-foreground text-sm leading-snug font-medium text-pretty"
                    >
                        {{ counter.label }}
                    </h3>
                    <ArrowRight
                        class="text-muted-foreground mt-0.5 size-4 shrink-0 transition-transform duration-200 ease-out group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </div>

                <div class="mt-auto">
                    <p
                        :class="[
                            tone.value,
                            'font-display tabular text-4xl leading-none font-semibold tracking-tight',
                        ]"
                    >
                        {{ counter.value }}
                    </p>
                    <!--
                        Sits tight under the number it belongs to, and well
                        clear of the label above: the gap does the grouping.
                    -->
                    <p
                        v-if="counter.hint"
                        class="text-muted-foreground mt-2 text-xs leading-relaxed text-pretty"
                    >
                        {{ counter.hint }}
                    </p>
                </div>
            </div>
        </article>
    </Link>
</template>
