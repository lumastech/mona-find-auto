<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    CircleHelp,
    TrendingUp,
} from '@lucide/vue';
import { computed } from 'vue';
import ConsoleChart from '@/components/admin/ConsoleChart.vue';
import Heading from '@/components/Heading.vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes/admin/finance';

/**
 * The platform's own numbers.
 *
 * Every figure on this page is a sum of journal lines — see FinanceMetrics on
 * the server for why none of them is computed from the orders table. Two
 * groups, and the distinction matters when reading them: the tiles across the
 * top are FLOWS bounded by the window, and the "where the money stands" row
 * is a POSITION at the end of it, which includes money that arrived before
 * the window opened.
 *
 * The cash check is deferred because it calls Lenco over the network. It is
 * the one number here that can come back "unknown", and unknown is rendered
 * as its own state rather than as a zero — a check that read an unreachable
 * gateway as an empty account would cry wolf every time the network blipped.
 */
type Totals = {
    gmvNgwee: number;
    orderCount: number;
    commissionNgwee: number;
    addonFeesNgwee: number;
    referralFeesNgwee: number;
    vatOnCommissionNgwee: number;
    refundsToBuyersNgwee: number;
    refundsAbsorbedNgwee: number;
    lencoFeesNgwee: number;
    grossRevenueNgwee: number;
    netRevenueNgwee: number;
};

type SeriesPoint = Totals & { bucket: string; label: string };

type BreakdownRow = Totals & {
    key: string;
    label: string;
    apportioned: boolean;
};

const props = defineProps<{
    window: { from: string; to: string; label: string };
    granularity: string;
    dimension: string;
    totals: Totals;
    positions: {
        platformCashNgwee: number;
        escrowHeldNgwee: number;
        payablesOutstandingNgwee: number;
        reserveHeldNgwee: number;
        vatOwedNgwee: number;
    };
    series: SeriesPoint[];
    breakdown: BreakdownRow[];
    granularities: { value: string; label: string }[];
    dimensions: { value: string; label: string; apportioned: boolean }[];
    cashCheck?: {
        ledgerNgwee: number;
        gatewayNgwee: number | null;
        varianceNgwee: number | null;
        status: 'balanced' | 'variance' | 'unknown';
        message: string;
        checkedAt: string;
    };
}>();

const apply = (changes: Record<string, string>): void => {
    router.get(
        dashboard.url(),
        {
            from: props.window.from,
            to: props.window.to,
            granularity: props.granularity,
            dimension: props.dimension,
            ...changes,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

/* Tailwind tokens are CSS variables; Chart.js needs literal colours. */
const palette = {
    gmv: '#0ea5e9',
    revenue: '#10b981',
    refunds: '#f43f5e',
};

const labels = computed(() => props.series.map((point) => point.label));

const tradeSeries = computed(() => [
    {
        label: 'GMV',
        values: props.series.map((point) => point.gmvNgwee),
        colour: palette.gmv,
    },
    {
        label: 'Refunded',
        values: props.series.map((point) => point.refundsToBuyersNgwee),
        colour: palette.refunds,
    },
]);

const revenueSeries = computed(() => [
    {
        label: 'Net revenue',
        values: props.series.map((point) => point.netRevenueNgwee),
        colour: palette.revenue,
    },
]);

const apportioned = computed(() =>
    props.breakdown.some((row) => row.apportioned),
);

const flowTiles = computed(() => [
    { label: 'GMV', value: props.totals.gmvNgwee, hint: 'What buyers paid.' },
    {
        label: 'Commission',
        value: props.totals.commissionNgwee,
        hint: 'Recognised on release or direct settlement.',
    },
    {
        label: 'Add-on fees',
        value: props.totals.addonFeesNgwee,
        hint: 'Per order, under the seller’s policy.',
    },
    {
        label: 'Referral fees',
        value: props.totals.referralFeesNgwee,
        hint: 'Orders introduced by a partner.',
    },
    {
        label: 'VAT on commission',
        value: props.totals.vatOnCommissionNgwee,
        hint: 'Owed to ZRA. Never revenue.',
    },
    {
        label: 'Refunded to buyers',
        value: props.totals.refundsToBuyersNgwee,
        hint: 'However it was funded.',
    },
    {
        label: 'Refunds absorbed',
        value: props.totals.refundsAbsorbedNgwee,
        hint: 'The platform’s own pocket.',
    },
    {
        label: 'Lenco fees',
        value: props.totals.lencoFeesNgwee,
        hint: 'Gateway charges on collections and payouts.',
    },
]);

const positionTiles = computed(() => [
    {
        label: 'Escrow held',
        value: props.positions.escrowHeldNgwee,
        hint: 'Buyer money on orders not yet settled.',
    },
    {
        label: 'Payables outstanding',
        value: props.positions.payablesOutstandingNgwee,
        hint: 'Owed to sellers, not yet paid out.',
    },
    {
        label: 'Reserve held',
        value: props.positions.reserveHeldNgwee,
        hint: 'Withheld from direct-settlement sellers.',
    },
    {
        label: 'VAT owed',
        value: props.positions.vatOwedNgwee,
        hint: 'Collected on commission, owed onward.',
    },
    {
        label: 'Platform cash',
        value: props.positions.platformCashNgwee,
        hint: 'What the books say is at Lenco.',
    },
]);
</script>

<template>
    <Head title="Finance" />

    <div class="space-y-6 p-4">
        <Heading
            title="Finance"
            description="Every figure here is a sum of ledger entries, never a total taken from the orders table."
        />

        <!-- The window, and how it is cut. -->
        <Card>
            <CardContent class="grid gap-4 pt-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1.5">
                    <Label for="from">From</Label>
                    <Input
                        id="from"
                        type="date"
                        :model-value="window.from"
                        @change="
                            apply({
                                from: ($event.target as HTMLInputElement).value,
                            })
                        "
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="to">To</Label>
                    <Input
                        id="to"
                        type="date"
                        :model-value="window.to"
                        @change="
                            apply({
                                to: ($event.target as HTMLInputElement).value,
                            })
                        "
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="granularity">Bucket</Label>
                    <select
                        id="granularity"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        :value="granularity"
                        @change="
                            apply({
                                granularity: (
                                    $event.target as HTMLSelectElement
                                ).value,
                            })
                        "
                    >
                        <option
                            v-for="option in granularities"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <Label for="dimension">Break down by</Label>
                    <select
                        id="dimension"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        :value="dimension"
                        @change="
                            apply({
                                dimension: ($event.target as HTMLSelectElement)
                                    .value,
                            })
                        "
                    >
                        <option
                            v-for="option in dimensions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
            </CardContent>
        </Card>

        <!-- The daily cash check: books against bank. -->
        <Deferred data="cashCheck">
            <template #fallback>
                <Skeleton class="h-24 w-full animate-pulse" />
            </template>

            <Alert
                v-if="cashCheck"
                :variant="
                    cashCheck.status === 'variance' ? 'destructive' : 'default'
                "
            >
                <CheckCircle2
                    v-if="cashCheck.status === 'balanced'"
                    class="size-4"
                />
                <CircleHelp
                    v-else-if="cashCheck.status === 'unknown'"
                    class="size-4"
                />
                <AlertTriangle v-else class="size-4" />

                <AlertTitle>
                    Daily balance check &middot; ledger platform cash against
                    Lenco
                </AlertTitle>
                <AlertDescription>
                    <p>{{ cashCheck.message }}</p>
                    <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                        <div class="flex gap-2">
                            <dt class="text-muted-foreground">Ledger</dt>
                            <dd><Money :amount="cashCheck.ledgerNgwee" /></dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="text-muted-foreground">Lenco</dt>
                            <dd>
                                <Money
                                    v-if="cashCheck.gatewayNgwee !== null"
                                    :amount="cashCheck.gatewayNgwee"
                                />
                                <span v-else>Not reported</span>
                            </dd>
                        </div>
                        <div
                            v-if="cashCheck.varianceNgwee !== null"
                            class="flex gap-2"
                        >
                            <dt class="text-muted-foreground">Variance</dt>
                            <dd>
                                <Money
                                    :amount="cashCheck.varianceNgwee"
                                    signed
                                />
                            </dd>
                        </div>
                    </dl>
                </AlertDescription>
            </Alert>
        </Deferred>

        <!-- Flows: what moved inside the window. -->
        <section class="space-y-3">
            <div class="flex items-baseline justify-between">
                <h2 class="text-lg font-semibold">
                    What moved &middot; {{ window.label }}
                </h2>
                <p class="text-muted-foreground text-sm">
                    {{ totals.orderCount }} order{{
                        totals.orderCount === 1 ? '' : 's'
                    }}
                    took payment
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Card v-for="tile in flowTiles" :key="tile.label">
                    <CardContent class="pt-6">
                        <p class="text-muted-foreground text-xs">
                            {{ tile.label }}
                        </p>
                        <p class="mt-1 text-xl font-semibold">
                            <Money :amount="tile.value" />
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ tile.hint }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card class="border-emerald-200 dark:border-emerald-900">
                <CardContent class="flex flex-wrap items-center gap-6 pt-6">
                    <TrendingUp class="size-5 text-emerald-600" />
                    <div>
                        <p class="text-muted-foreground text-xs">
                            Gross revenue
                        </p>
                        <p class="text-lg font-semibold">
                            <Money :amount="totals.grossRevenueNgwee" />
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs">Net revenue</p>
                        <p class="text-lg font-semibold">
                            <Money :amount="totals.netRevenueNgwee" />
                        </p>
                    </div>
                    <p class="text-muted-foreground max-w-md text-xs">
                        Net is the three fee lines less refunds the platform
                        funded itself and Lenco&rsquo;s charges. VAT is
                        excluded: it is collected on commission and owed onward
                        to ZRA, so it was never the platform&rsquo;s.
                    </p>
                </CardContent>
            </Card>
        </section>

        <!-- The same figures over time. -->
        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <h3 class="font-semibold">Trade</h3>
                    <p class="text-muted-foreground text-sm">
                        GMV against what went back to buyers.
                    </p>
                </CardHeader>
                <CardContent>
                    <ConsoleChart :labels="labels" :series="tradeSeries" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <h3 class="font-semibold">Net revenue</h3>
                    <p class="text-muted-foreground text-sm">
                        After absorbed refunds and gateway charges.
                    </p>
                </CardHeader>
                <CardContent>
                    <ConsoleChart
                        :labels="labels"
                        :series="revenueSeries"
                        type="bar"
                    />
                </CardContent>
            </Card>
        </div>

        <!-- Positions: where the money stands at the end of the window. -->
        <section class="space-y-3">
            <div>
                <h2 class="text-lg font-semibold">Where the money stands</h2>
                <p class="text-muted-foreground text-sm">
                    Balances at the end of {{ window.label }}, including money
                    that arrived before it. Not movements within the window.
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <Card v-for="tile in positionTiles" :key="tile.label">
                    <CardContent class="pt-6">
                        <p class="text-muted-foreground text-xs">
                            {{ tile.label }}
                        </p>
                        <p class="mt-1 text-xl font-semibold">
                            <Money :amount="tile.value" signed />
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ tile.hint }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <!-- The breakdown. -->
        <Card>
            <CardHeader class="flex flex-row items-center justify-between">
                <div>
                    <h3 class="font-semibold">
                        By
                        {{
                            dimensions
                                .find((option) => option.value === dimension)
                                ?.label.toLowerCase()
                        }}
                    </h3>
                    <p class="text-muted-foreground text-sm">
                        {{ window.label }}
                    </p>
                </div>
                <Badge v-if="apportioned" variant="secondary">
                    Apportioned by item value
                </Badge>
            </CardHeader>
            <CardContent>
                <p
                    v-if="apportioned"
                    class="text-muted-foreground mb-3 text-xs"
                >
                    An order spanning several categories earned one commission.
                    These rows split each order across its categories in
                    proportion to what was bought, so they still total the
                    figures above exactly.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="text-muted-foreground border-b text-left text-xs"
                        >
                            <tr>
                                <th class="py-2 pr-4 font-medium">Group</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Orders
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    GMV
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Commission
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    VAT
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Refunded
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Net revenue
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in breakdown"
                                :key="row.key"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4">{{ row.label }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ row.orderCount }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="row.gmvNgwee" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="row.commissionNgwee" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="row.vatOnCommissionNgwee" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="row.refundsToBuyersNgwee" />
                                </td>
                                <td class="py-2 text-right">
                                    <Money :amount="row.netRevenueNgwee" />
                                </td>
                            </tr>
                            <tr v-if="breakdown.length === 0">
                                <td
                                    colspan="7"
                                    class="text-muted-foreground py-6 text-center"
                                >
                                    Nothing traded in this window.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
