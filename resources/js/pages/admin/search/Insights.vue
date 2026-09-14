<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { SearchX, TrendingUp } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { searchInsights } from '@/routes/admin';

/**
 * The searches buyers ran that came back empty.
 *
 * This is the reference-data backlog, written by buyers rather than guessed
 * at in a meeting. A term near the top of this list is one of three things: a
 * make or model the reference lists never had, a part category nobody thought
 * to create, or a genuine gap in what Zambian sellers are stocking — and all
 * three are worth somebody's morning.
 */
const props = defineProps<{
    days: number;
    summary: {
        searches: number;
        zero_results: number;
        zero_result_rate: number;
        distinct_terms: number;
    };
    zeroResultTerms: Array<{
        term: string;
        searches: number;
        last_searched_at: string;
    }>;
    topTerms: Array<{ term: string; searches: number }>;
}>();

const windows = [7, 30, 90];

const setWindow = (days: number): void => {
    router.get(
        searchInsights.url({ query: { days } }),
        {},
        { preserveState: true, replace: true },
    );
};

const formatDate = (iso: string): string =>
    new Date(iso).toLocaleDateString('en-ZM', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
</script>

<template>
    <Head title="Search insights" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading
                title="Search insights"
                description="What buyers looked for, and what the catalogue could not answer."
            />

            <div class="space-y-1">
                <Label for="insights-window" class="text-xs">Window</Label>
                <select
                    id="insights-window"
                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                    :value="props.days"
                    @change="
                        setWindow(
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option v-for="days in windows" :key="days" :value="days">
                        Last {{ days }} days
                    </option>
                </select>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-muted-foreground text-xs font-medium"
                    >
                        Searches
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-2xl font-semibold">
                    {{ summary.searches }}
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-muted-foreground text-xs font-medium"
                    >
                        Found nothing
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-2xl font-semibold">
                    {{ summary.zero_results }}
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-muted-foreground text-xs font-medium"
                    >
                        Zero-result rate
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-2xl font-semibold">
                    {{ summary.zero_result_rate }}%
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-muted-foreground text-xs font-medium"
                    >
                        Distinct terms
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-2xl font-semibold">
                    {{ summary.distinct_terms }}
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <SearchX class="size-4" aria-hidden="true" />
                        Searches that found nothing
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="zeroResultTerms.length" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead
                                class="text-muted-foreground border-b text-left text-xs"
                            >
                                <tr>
                                    <th scope="col" class="pb-2 font-medium">
                                        Term
                                    </th>
                                    <th
                                        scope="col"
                                        class="pb-2 text-right font-medium"
                                    >
                                        Searches
                                    </th>
                                    <th
                                        scope="col"
                                        class="pb-2 text-right font-medium"
                                    >
                                        Last searched
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="row in zeroResultTerms"
                                    :key="row.term"
                                >
                                    <td class="py-2 font-medium">
                                        {{ row.term }}
                                    </td>
                                    <td class="py-2 text-right">
                                        {{ row.searches }}
                                    </td>
                                    <td
                                        class="text-muted-foreground py-2 text-right"
                                    >
                                        {{ formatDate(row.last_searched_at) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p
                        v-else
                        class="text-muted-foreground py-8 text-center text-sm"
                    >
                        Every search in this window found something.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <TrendingUp class="size-4" aria-hidden="true" />
                        Busiest searches
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <ul v-if="topTerms.length" class="divide-y text-sm">
                        <li
                            v-for="row in topTerms"
                            :key="row.term"
                            class="flex items-center justify-between py-2"
                        >
                            <span class="truncate">{{ row.term }}</span>
                            <span class="text-muted-foreground">
                                {{ row.searches }}
                            </span>
                        </li>
                    </ul>

                    <p
                        v-else
                        class="text-muted-foreground py-8 text-center text-sm"
                    >
                        Nothing searched yet.
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
