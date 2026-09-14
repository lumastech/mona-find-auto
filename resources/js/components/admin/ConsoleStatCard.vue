<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowUpRight, Minus } from '@lucide/vue';
import { computed } from 'vue';
import {
    formatChange,
    formatStatValue,
    sentimentOf,
    sparklinePoints,
} from '@/lib/console';
import { defaultCurrency } from '@/lib/money';
import type { ConsoleStat } from '@/types';

/**
 * One measurement, drawn quieter than the queue tiles above it.
 *
 * The console is a to-do list first. These say how the platform is doing,
 * which nobody clears and nobody has to act on this morning, so the card is
 * flatter, the number smaller and the whole block sits below the work — a
 * statistic rendered as loudly as an open dispute trains staff to read
 * neither.
 *
 * The comparison carries the meaning. "K 412,000" is a number; "K 412,000, up
 * a fifth on the previous thirty days" is a fact somebody can use, so where a
 * module sent no previous figure the tile says so plainly rather than drawing
 * a 0% that looks like a measurement.
 */
const props = defineProps<{
    stat: ConsoleStat;
    /** Position in the grid, used to stagger the entrance. */
    index?: number;
}>();

const page = usePage();

const currency = computed(
    () => page.props.platform?.currency ?? defaultCurrency,
);

const value = computed(() =>
    formatStatValue(props.stat.value, props.stat.format, currency.value),
);

const sentiment = computed(() =>
    props.stat.change === null
        ? 'flat'
        : sentimentOf(props.stat.change, props.stat.direction),
);

/**
 * Green for good and red for bad, taken from the semantic roles rather than a
 * palette — and deliberately NOT gold, which on this platform means "MonaFind
 * vouched for it" and stops meaning anything if it decorates a trend.
 */
const changeTone = computed(
    () =>
        ({
            good: 'text-success bg-success-muted/50',
            bad: 'text-danger bg-danger-muted/50',
            flat: 'text-muted-foreground bg-muted/60',
        })[sentiment.value],
);

const ChangeIcon = computed(() => {
    if (props.stat.change === null || props.stat.change === 0) {
        return Minus;
    }

    return props.stat.change > 0 ? ArrowUpRight : ArrowDownRight;
});

const change = computed(() =>
    props.stat.change === null ? null : formatChange(props.stat.change),
);

/* A viewBox the SVG scales out of, so the drawing never depends on layout. */
const SPARK_WIDTH = 120;
const SPARK_HEIGHT = 28;

const spark = computed(() =>
    props.stat.spark && props.stat.spark.length > 1
        ? sparklinePoints(props.stat.spark, SPARK_WIDTH, SPARK_HEIGHT)
        : null,
);

/**
 * The direction, spelled out for a reader who gets none of the drawing.
 *
 * An arrow glyph reads as nothing at all, and "+21.4%" on its own leaves a
 * screen-reader user with a percentage and no idea which way it points. The
 * word goes in the chip rather than into a label on the whole card, because a
 * label there would replace the hint line instead of adding to it.
 */
const changeSpoken = computed(() => {
    if (props.stat.change === null || props.stat.change === 0) {
        return 'unchanged from the previous period,';
    }

    return props.stat.change > 0
        ? 'up on the previous period,'
        : 'down on the previous period,';
});

/** Entrances stagger ~40ms apart; past the first handful it just feels slow. */
const delay = computed(() => `${Math.min(props.index ?? 0, 7) * 40}ms`);

/** A tile with somewhere to go is a link; one without is not a dead link. */
const shell = computed(() => (props.stat.href ? Link : 'div'));
</script>

<template>
    <component
        :is="shell"
        :href="stat.href ?? undefined"
        :style="{ animationDelay: delay }"
        class="group border-border/70 bg-card/60 shadow-elev-1 motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-1 motion-safe:fill-mode-both focus-visible:ring-ring relative flex h-full flex-col gap-3 rounded-xl border p-4 duration-300 ease-out focus-visible:ring-2 focus-visible:outline-none"
        :class="
            stat.href
                ? 'hover:border-border hover:bg-card hover:shadow-elev-2 transition-[background-color,border-color,box-shadow] duration-200 ease-out'
                : ''
        "
    >
        <p
            class="text-muted-foreground text-xs leading-snug font-medium text-pretty"
        >
            {{ stat.label }}
        </p>

        <div class="flex items-end justify-between gap-3">
            <p
                class="font-display tabular text-2xl leading-none font-semibold tracking-tight"
            >
                {{ value }}
            </p>

            <!--
                Drawn only where there is something to compare against. A tile
                for a standing balance shows the balance and says nothing,
                which is the honest answer.
            -->
            <span
                v-if="change !== null"
                :class="[
                    changeTone,
                    'tabular inline-flex shrink-0 items-center gap-0.5 rounded-md px-1.5 py-0.5 text-xs font-medium',
                ]"
            >
                <component :is="ChangeIcon" class="size-3" aria-hidden="true" />
                <span class="sr-only">{{ changeSpoken }}</span>
                {{ change }}
            </span>
        </div>

        <!--
            Scaled to its own peak, so a flat fortnight looks flat. Decorative
            in the strict sense: every number it carries is either in the value
            above it or on the screen the tile links to.
        -->
        <svg
            v-if="spark"
            :viewBox="`0 0 ${SPARK_WIDTH} ${SPARK_HEIGHT}`"
            preserveAspectRatio="none"
            class="text-primary/35 group-hover:text-primary/60 h-7 w-full transition-colors duration-200 ease-out"
            aria-hidden="true"
            focusable="false"
        >
            <polyline
                :points="spark"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
        </svg>

        <p
            v-if="stat.hint"
            class="text-muted-foreground/80 mt-auto text-xs leading-relaxed text-pretty"
        >
            {{ stat.hint }}
        </p>
    </component>
</template>
