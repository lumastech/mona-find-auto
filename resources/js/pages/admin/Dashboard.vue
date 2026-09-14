<script setup lang="ts">
import { Deferred, Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, ShieldAlert } from '@lucide/vue';
import { computed, defineAsyncComponent } from 'vue';
import ConsoleQueueCard from '@/components/admin/ConsoleQueueCard.vue';
import ConsoleStatCard from '@/components/admin/ConsoleStatCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import adminStaff from '@/routes/admin/staff';
import type {
    ConsoleChart,
    ConsoleCounter,
    ConsoleShortcut,
    ConsoleStat,
    ConsoleWindow,
} from '@/types';

/**
 * The staff console's home screen: what is waiting, then how it is going.
 *
 * Every number here is contributed by the module that owns the queue or the
 * figure behind it, so this page renders whatever came back rather than
 * knowing what a payout batch or a stale listing is. A tile the viewer's role
 * cannot act on never arrives — a dashboard full of doors that refuse you is
 * one nobody reads.
 *
 * The screen is a to-do list, and the layout has to say so. Queues with work
 * in them are cards; queues that are clear are one-line chips underneath.
 * The earlier version drew all nine at the same size, which meant the page
 * opened on a wall of identical boxes with the one number that needed
 * somebody this morning sitting somewhere inside it.
 *
 * How the platform is DOING comes after all of that, drawn quieter, and
 * arrives a moment later. It is worth having — a console that only ever shows
 * backlogs never tells anybody the business is growing — but it is nobody's
 * next action, so it never sits above something that is, and it never holds
 * up the dispute count on the way in.
 */
const props = defineProps<{
    counters: ConsoleCounter[];
    shortcuts: ConsoleShortcut[];
    window: ConsoleWindow;
    twoFactorOutstanding?: number | null;
    stats?: ConsoleStat[];
    charts?: ConsoleChart[];
}>();

/**
 * Chart.js is 64kB gzipped and most of this screen is not a chart.
 *
 * Loaded on demand rather than imported outright, so a console whose charts
 * came back empty — a platform in its first week, or a role with no chart to
 * see — never downloads a plotting library to render nothing. The brief asks
 * for a staff console usable on a low-end Android on mobile data, and this is
 * the largest single thing that would otherwise be on the critical path.
 */
const ConsoleChartCard = defineAsyncComponent(
    () => import('@/components/admin/ConsoleChart.vue'),
);

const weight = (counter: ConsoleCounter): number =>
    ({ critical: 3, warning: 2, neutral: 1 })[counter.tone];

/** Loudest tone first, then the biggest pile within a tone. */
const needsAttention = computed(() =>
    props.counters
        .filter((counter) => counter.value > 0)
        .sort((a, b) => weight(b) - weight(a) || b.value - a.value),
);

const clear = computed(() =>
    props.counters.filter((counter) => counter.value === 0),
);

const outstanding = computed(() =>
    props.counters.reduce((total, counter) => total + counter.value, 0),
);

/**
 * The one line at the top has to be worth reading on its own, because on a
 * good day it is the only line anybody reads.
 */
const summary = computed(() => {
    if (props.counters.length === 0) {
        return 'No queues are assigned to your role.';
    }

    if (needsAttention.value.length === 0) {
        return 'Every queue is clear. Nothing is waiting on staff.';
    }

    const items = `${outstanding.value} item${outstanding.value === 1 ? '' : 's'}`;
    const queues = `${needsAttention.value.length} of ${props.counters.length} queues`;

    return `${items} waiting across ${queues}.`;
});
</script>

<template>
    <Head title="Staff console" />

    <!--
        Sections are 40px apart and the rows inside them 12–16px: the gap does
        the grouping, so none of these blocks needs a box drawn round it.
    -->
    <div class="space-y-10 p-4 sm:p-6">
        <header class="space-y-1">
            <h1
                class="font-display text-2xl font-semibold tracking-tight text-balance"
            >
                Staff console
            </h1>
            <p class="text-muted-foreground text-sm text-pretty">
                {{ summary }}
            </p>
        </header>

        <section v-if="needsAttention.length > 0" class="space-y-4">
            <div class="flex items-baseline gap-2">
                <h2
                    class="font-display text-muted-foreground text-xs font-semibold tracking-[0.14em] uppercase"
                >
                    Needs attention
                </h2>
                <span class="text-muted-foreground/70 tabular text-xs">
                    {{ needsAttention.length }}
                </span>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <ConsoleQueueCard
                    v-for="(counter, index) in needsAttention"
                    :key="counter.key"
                    :counter="counter"
                    :index="index"
                />
            </div>
        </section>

        <!-- The good day. Worth saying plainly rather than showing nine zeroes. -->
        <section
            v-else-if="counters.length > 0"
            class="border-success/25 bg-success-muted/40 shadow-elev-1 flex items-center gap-3 rounded-xl border p-5"
        >
            <CheckCircle2 class="text-success size-5 shrink-0" />
            <div class="min-w-0">
                <p class="text-sm font-medium">Every queue is clear</p>
                <p class="text-muted-foreground mt-0.5 text-xs text-pretty">
                    Nothing is waiting on a decision, a payout or a dispute.
                </p>
            </div>
        </section>

        <!--
            Deferred because it walks every staff account; the queues above are
            what somebody opened this screen for. The skeleton is the shape of
            the notice it becomes, so nothing jumps when it lands.
        -->
        <Deferred data="twoFactorOutstanding">
            <template #fallback>
                <Skeleton class="h-[4.5rem] w-full rounded-xl" />
            </template>

            <section
                v-if="twoFactorOutstanding"
                class="border-danger/30 bg-danger-muted/40 shadow-elev-1 flex items-start gap-3 rounded-xl border p-5"
            >
                <ShieldAlert class="text-danger mt-0.5 size-5 shrink-0" />
                <div class="min-w-0 space-y-1">
                    <p class="text-sm font-medium text-pretty">
                        {{ twoFactorOutstanding }} staff
                        {{
                            twoFactorOutstanding === 1
                                ? 'account has'
                                : 'accounts have'
                        }}
                        not set up two-factor authentication
                    </p>
                    <p class="text-muted-foreground text-xs text-pretty">
                        They are held at the enrolment screen and can reach
                        nothing until they finish.
                        <Link
                            :href="adminStaff.index().url"
                            class="text-foreground font-medium underline underline-offset-4 transition-colors duration-150 ease-out hover:no-underline"
                        >
                            Open staff
                        </Link>
                    </p>
                </div>
            </section>
        </Deferred>

        <section v-if="clear.length > 0" class="space-y-3">
            <h2
                class="font-display text-muted-foreground text-xs font-semibold tracking-[0.14em] uppercase"
            >
                Clear
            </h2>

            <!--
                Deliberately not cards. These are still reachable, but they
                have earned no more of the eye than a line of text.
            -->
            <ul class="flex flex-wrap gap-2">
                <li v-for="counter in clear" :key="counter.key">
                    <Link
                        :href="counter.href"
                        :aria-label="`${counter.label}: 0`"
                        class="border-border/70 bg-card/50 text-muted-foreground hover:text-foreground hover:border-border hover:bg-card focus-visible:ring-ring flex items-center gap-2 rounded-lg border px-3 py-2 text-xs transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <span>{{ counter.label }}</span>
                        <span class="tabular text-muted-foreground/60">0</span>
                    </Link>
                </li>
            </ul>
        </section>

        <!--
            Deferred as one group with the charts below, so the strip and the
            graphs land together rather than shifting the page twice under
            somebody already reading it.
        -->
        <Deferred data="stats">
            <template #fallback>
                <section class="space-y-4">
                    <div class="h-4 w-40">
                        <Skeleton class="h-full w-full rounded" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Skeleton
                            v-for="placeholder in 4"
                            :key="placeholder"
                            class="h-[7.5rem] rounded-xl"
                        />
                    </div>
                </section>
            </template>

            <section v-if="stats && stats.length > 0" class="space-y-4">
                <div class="flex flex-wrap items-baseline gap-2">
                    <h2
                        class="font-display text-muted-foreground text-xs font-semibold tracking-[0.14em] uppercase"
                    >
                        How it is going
                    </h2>
                    <!--
                        The window is stated once, here, rather than repeated
                        on every tile: all of them cover the same thirty days
                        and saying so eleven times is eleven things to read.
                    -->
                    <span class="text-muted-foreground/70 text-xs text-pretty">
                        {{ window.label }}, against the previous
                        {{ window.days }} days
                    </span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <ConsoleStatCard
                        v-for="(stat, index) in stats"
                        :key="stat.key"
                        :stat="stat"
                        :index="index"
                    />
                </div>
            </section>
        </Deferred>

        <!--
            Charts come back only where something was actually plotted, so a
            platform in its first week shows no empty axes — see
            ConsoleChart::hasData() on the server.
        -->
        <Deferred data="charts">
            <template #fallback>
                <Skeleton class="h-72 w-full rounded-xl" />
            </template>

            <section
                v-if="charts && charts.length > 0"
                class="grid gap-4 xl:grid-cols-2"
            >
                <article
                    v-for="chart in charts"
                    :key="chart.key"
                    class="border-border/70 bg-card/60 shadow-elev-1 space-y-4 rounded-xl border p-5"
                >
                    <header class="flex items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <h3 class="text-sm font-medium">
                                {{ chart.title }}
                            </h3>
                            <p
                                v-if="chart.description"
                                class="text-muted-foreground text-xs leading-relaxed text-pretty"
                            >
                                {{ chart.description }}
                            </p>
                        </div>
                        <Link
                            v-if="chart.href"
                            :href="chart.href"
                            class="text-muted-foreground hover:text-foreground focus-visible:ring-ring shrink-0 rounded text-xs font-medium underline underline-offset-4 transition-colors duration-150 ease-out hover:no-underline focus-visible:ring-2 focus-visible:outline-none"
                        >
                            Open
                        </Link>
                    </header>

                    <!--
                        The height is reserved here as well as inside the
                        chart, so the card does not collapse and snap open
                        again while Chart.js is still on its way down.
                    -->
                    <div class="h-[220px]">
                        <ConsoleChartCard
                            :labels="chart.labels"
                            :series="chart.series"
                            :type="chart.type"
                            :format="chart.format"
                            :height="220"
                        />
                    </div>
                </article>
            </section>
        </Deferred>

        <section class="space-y-3">
            <h2
                class="font-display text-muted-foreground text-xs font-semibold tracking-[0.14em] uppercase"
            >
                Everything else
            </h2>

            <div class="grid gap-3 sm:grid-cols-3">
                <Link
                    v-for="shortcut in shortcuts"
                    :key="shortcut.href"
                    :href="shortcut.href"
                    class="group border-border/70 bg-card/50 hover:border-border hover:bg-card hover:shadow-elev-1 focus-visible:ring-ring flex items-start gap-3 rounded-lg border p-4 transition-[background-color,border-color,box-shadow] duration-200 ease-out focus-visible:ring-2 focus-visible:outline-none"
                >
                    <div class="min-w-0 flex-1 space-y-0.5">
                        <p class="text-sm font-medium">{{ shortcut.title }}</p>
                        <p
                            class="text-muted-foreground text-xs leading-relaxed text-pretty"
                        >
                            {{ shortcut.description }}
                        </p>
                    </div>
                    <ArrowRight
                        class="text-muted-foreground mt-0.5 size-4 shrink-0 transition-transform duration-200 ease-out group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </Link>
            </div>
        </section>
    </div>
</template>
