<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
    type ChartOptions,
} from 'chart.js';
import { computed } from 'vue';
import { Bar, Line } from 'vue-chartjs';
import { formatStatValue } from '@/lib/console';
import { defaultCurrency } from '@/lib/money';
import type { ConsoleStatFormat } from '@/types';

/**
 * One chart of console figures, in the platform's own formatting.
 *
 * Shared by the finance dashboard and the staff console's home screen, which
 * is why it takes a `format` rather than assuming money: the same axis logic
 * has to draw K 42,000.00 and 17 orders without two components drifting apart.
 *
 * ## Why the registration is explicit
 *
 * Chart.js ships a `Chart.register(...registerables)` convenience that pulls
 * in every controller, scale and plugin it has. Naming the eight pieces these
 * charts actually use keeps the admin bundle from carrying radar, polar-area
 * and doughnut renderers nobody opens — which matters here, because the brief
 * asks for a console usable on a low-end Android.
 *
 * ## Values never become floats
 *
 * Amounts arrive as integer ngwee and stay integers all the way to the axis.
 * Chart.js needs a number to place a point, so the value plotted is the ngwee
 * count itself; every LABEL — the tick, the tooltip — is rendered through the
 * same formatMoney() the <Money> component uses. Dividing by 100 to "make it
 * kwacha" before plotting is how a rounding error gets into a finance screen.
 */
ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    Filler,
    Tooltip,
    Legend,
);

const {
    labels,
    series,
    type = 'line',
    format = 'money',
    height = 260,
} = defineProps<{
    /** The bucket labels along the x-axis. */
    labels: string[];
    /** One entry per plotted line or bar. Values are integers. */
    series: { label: string; values: number[]; colour: string }[];
    type?: 'line' | 'bar';
    /** What the values are: integer ngwee, a tally, or hundredths of a percent. */
    format?: ConsoleStatFormat;
    height?: number;
}>();

const page = usePage();

const currency = computed(
    () => page.props.platform?.currency ?? defaultCurrency,
);

/** Shared with the stat tiles, so an axis and a tile never disagree. */
const readable = (value: number): string =>
    formatStatValue(value, format, currency.value);

const data = computed(() => ({
    labels,
    datasets: series.map((entry) => ({
        label: entry.label,
        data: entry.values,
        borderColor: entry.colour,
        backgroundColor: type === 'bar' ? entry.colour : `${entry.colour}22`,
        borderWidth: 2,
        pointRadius: 0,
        pointHitRadius: 12,
        tension: 0.25,
        fill: type === 'line',
    })),
}));

/**
 * Chart.js types its options against ONE chart kind, so a single
 * `ChartOptions<'line' | 'bar'>` satisfies neither component's prop. The
 * shape is built once and asserted per kind below, which keeps the two charts
 * genuinely identical instead of two copies that drift apart.
 *
 * The tooltip callback is typed structurally rather than as `TooltipItem<T>`
 * for the same reason: all it reads is the series label and the raw value,
 * and naming the generic here would tie one shared object to one chart kind.
 */
const baseOptions = () => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' as const, intersect: false },
    plugins: {
        legend: {
            display: series.length > 1,
            position: 'bottom' as const,
            labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true },
        },
        tooltip: {
            callbacks: {
                label: (item: {
                    dataset: { label?: string };
                    raw: unknown;
                }): string =>
                    `${item.dataset.label}: ${readable(Number(item.raw ?? 0))}`,
            },
        },
    },
    scales: {
        x: { grid: { display: false } },
        y: {
            beginAtZero: true,
            ticks: {
                /* Ticks are values too — an axis of raw ngwee counts is unreadable. */
                callback: (value: string | number): string =>
                    readable(Number(value)),
                /* A tally has no halves, so never offer a tick between two. */
                precision: format === 'count' ? 0 : undefined,
            },
        },
    },
});

const lineOptions = computed(
    () => baseOptions() as unknown as ChartOptions<'line'>,
);
const barOptions = computed(
    () => baseOptions() as unknown as ChartOptions<'bar'>,
);
</script>

<template>
    <div :style="{ height: `${height}px` }">
        <Line v-if="type === 'line'" :data="data" :options="lineOptions" />
        <Bar v-else :data="data" :options="barOptions" />
    </div>
</template>
