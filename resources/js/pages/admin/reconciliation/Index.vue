<script setup lang="ts">
import { Deferred, Head, Link, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CircleHelp,
    RefreshCw,
    TriangleAlert,
} from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Nightly reconciliation, one row a night.
 *
 * Clean runs are listed alongside the others on purpose: "ran and found
 * nothing" and "never ran" look identical if only exceptions are shown, and
 * they could hardly be more different.
 */
defineProps<{
    /**
     * The daily balance check. Deferred, because it calls Lenco.
     *
     * It sits above the runs because it answers a question they cannot: a
     * nightly run compares a day line by line and can pass while the running
     * totals are wrong, whereas this compares what the books say is at Lenco
     * with what Lenco says. "Unknown" is its own state and never rendered as
     * a zero — a check that read an unreachable gateway as an empty account
     * would cry wolf every time the network blipped.
     */
    cashCheck?: {
        ledgerNgwee: number;
        gatewayNgwee: number | null;
        varianceNgwee: number | null;
        status: 'balanced' | 'variance' | 'unknown';
        message: string;
        checkedAt: string;
    };
    runs: Array<{
        id: number;
        date: string;
        status: string;
        statusLabel: string;
        exceptionCount: number;
        outstandingCount: number;
        gatewayTotalNgwee: number;
        ledgerTotalNgwee: number;
        varianceNgwee: number;
        url: string;
    }>;
    pagination: { currentPage: number; lastPage: number; total: number };
}>();

const rerun = useForm({ date: '' });
</script>

<template>
    <Head title="Reconciliation" />

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Reconciliation
            </h1>
            <p class="text-muted-foreground mt-1 text-sm">
                What Lenco says happened, against what our books say happened.
            </p>
        </div>

        <!-- Books against bank: one number against one number, right now. -->
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
                <TriangleAlert v-else class="size-4" />

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

        <Card>
            <CardHeader>
                <h2 class="font-medium">Re-run a day</h2>
            </CardHeader>
            <CardContent class="flex flex-wrap items-end gap-3">
                <div class="grid gap-2">
                    <Label for="date">Date</Label>
                    <Input id="date" v-model="rerun.date" type="date" />
                </div>
                <Button
                    variant="outline"
                    :disabled="rerun.processing || !rerun.date"
                    @click="rerun.post('/admin/reconciliation')"
                >
                    <RefreshCw class="size-4" aria-hidden="true" />
                    Re-run
                </Button>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium">Result</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Gateway
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Ledger
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Variance
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="run in runs" :key="run.id" class="border-t">
                            <td class="px-4 py-2">
                                <Link
                                    :href="run.url"
                                    class="font-medium hover:underline"
                                >
                                    {{ run.date }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">
                                <span class="flex items-center gap-2">
                                    <CheckCircle2
                                        v-if="run.status === 'clean'"
                                        class="size-4 text-emerald-600"
                                        aria-hidden="true"
                                    />
                                    <TriangleAlert
                                        v-else
                                        class="size-4 text-amber-500"
                                        aria-hidden="true"
                                    />
                                    <Badge
                                        :variant="
                                            run.status === 'clean'
                                                ? 'secondary'
                                                : 'destructive'
                                        "
                                    >
                                        {{ run.statusLabel }}
                                    </Badge>
                                    <span
                                        v-if="run.outstandingCount > 0"
                                        class="text-muted-foreground text-xs"
                                    >
                                        {{ run.outstandingCount }} outstanding
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="run.gatewayTotalNgwee" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="run.ledgerTotalNgwee" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="run.varianceNgwee" signed />
                            </td>
                        </tr>
                        <tr v-if="runs.length === 0">
                            <td
                                colspan="5"
                                class="text-muted-foreground px-4 py-8 text-center"
                            >
                                Reconciliation has not run yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
