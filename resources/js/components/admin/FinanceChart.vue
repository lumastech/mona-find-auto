<script setup lang="ts">
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
import { usePage } from '@inertiajs/vue3';
import { defaultCurrency, formatMoney } from '@/lib/money';

/**
 * One chart of ledger figures, in the platform's own money formatting.
 *
 * ## Why the registration is explicit
 *
 * Chart.js ships a `Chart.register(...registerables)` convenience that pulls
 * in every controller, scale and plugin it has. Naming the eight pieces these
 * charts actually use keeps the admin bundle from carrying radar, polar-area
 * and doughnut renderers nobody opens — which matters here, because the brief
 * asks for a console usable on a low-end Android.
 *
 * ## Money never becomes a float
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
    height = 260,
} = defineProps<{
    /** The bucket labels along the x-axis. */
    labels: string[];
    /** One entry per plotted line or bar. Values are integer ngwee. */
    series: { label: string; values: number[]; colour: string }[];
    type?: 'line' | 'bar';
    height?: number;
}>();

const page = usePage();

const currency = computed(
    () => page.props.platform?.currency ?? defaultCurrency,
);

const money = (ngwee: number): string =>
    formatMoney(ngwee, currency.value, { withSymbol: true });

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
                    `${item.dataset.label}: ${money(Number(item.raw ?? 0))}`,
            },
        },
    },
    scales: {
        x: { grid: { display: false } },
        y: {
            beginAtZero: true,
            ticks: {
                /* Ticks are money too — an axis of raw ngwee counts is unreadable. */
                callback: (value: string | number): string =>
                    money(Number(value)),
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
