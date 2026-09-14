<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Info, Landmark, ShieldQuestion, Wallet } from '@lucide/vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';

/**
 * What a seller is owed, and when it arrives.
 *
 * The three figures are shown separately with the reason attached, because a
 * seller who sees one number always assumes it is all of their money. Payable
 * is waiting for the next run; reserve is theirs but held against disputes;
 * and escrow — money from orders not yet completed — is not theirs at all
 * yet, so far as the ledger is concerned.
 */
defineProps<{
    summary: {
        payableNgwee: number;
        reserveNgwee: number;
        totalNgwee: number;
        paymentMode: string;
        paymentModeLabel: string;
        paymentModeDescription: string;
        carriesReserve: boolean;
        reservePercent: number;
    };
    nextPayout: {
        reference: string;
        status: string;
        statusLabel: string;
        scheduledFor: string | null;
    } | null;
    payouts: Array<{
        reference: string;
        batchReference: string;
        status: string;
        statusLabel: string;
        amountNgwee: number;
        method: string | null;
        failureReason: string | null;
        sentAt: string | null;
        settledAt: string | null;
    }>;
    pagination: { currentPage: number; lastPage: number; total: number };
    hasPayoutAccount: boolean;
    minimumPayoutNgwee: number;
}>();

function toneFor(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'paid') {
        return 'default';
    }

    if (status === 'failed' || status === 'blocked') {
        return 'destructive';
    }

    return 'secondary';
}
</script>

<template>
    <Head title="Earnings" />

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Earnings</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                {{ summary.paymentModeDescription }}
            </p>
        </div>

        <Alert v-if="!hasPayoutAccount" variant="destructive">
            <AlertTitle>We have nowhere to pay you</AlertTitle>
            <AlertDescription class="flex flex-wrap items-center gap-3">
                <span
                    >Add a payout account and you will be included in the next
                    run.</span
                >
                <Button as-child size="sm" variant="outline">
                    <Link href="/seller/payout-accounts">Add an account</Link>
                </Button>
            </AlertDescription>
        </Alert>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card>
                <CardHeader class="pb-2">
                    <div
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        <Wallet class="size-4" aria-hidden="true" /> Ready to
                        pay out
                    </div>
                </CardHeader>
                <CardContent>
                    <Money
                        :amount="summary.payableNgwee"
                        class="text-2xl font-semibold"
                    />
                    <p class="text-muted-foreground mt-1 text-xs">
                        Paid from
                        <Money :amount="minimumPayoutNgwee" /> upwards; smaller
                        balances roll into the next run.
                    </p>
                </CardContent>
            </Card>

            <Card v-if="summary.carriesReserve">
                <CardHeader class="pb-2">
                    <div
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        <ShieldQuestion class="size-4" aria-hidden="true" />
                        Held in reserve
                    </div>
                </CardHeader>
                <CardContent>
                    <Money
                        :amount="summary.reserveNgwee"
                        class="text-2xl font-semibold"
                    />
                    <p class="text-muted-foreground mt-1 text-xs">
                        {{ summary.reservePercent }}% of recent sales, released
                        as orders pass their dispute window. This money is
                        yours.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <div
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        <Landmark class="size-4" aria-hidden="true" />
                        Settlement
                    </div>
                </CardHeader>
                <CardContent>
                    <Badge variant="outline">{{
                        summary.paymentModeLabel
                    }}</Badge>
                    <p
                        v-if="nextPayout"
                        class="text-muted-foreground mt-2 text-xs"
                    >
                        Next run {{ nextPayout.reference }} —
                        {{ nextPayout.statusLabel }}
                    </p>
                    <p v-else class="text-muted-foreground mt-2 text-xs">
                        No payout run is open right now.
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <h2 class="font-medium">Payout history</h2>
            </CardHeader>
            <CardContent>
                <p
                    v-if="payouts.length === 0"
                    class="text-muted-foreground py-8 text-center text-sm"
                >
                    You have not been paid out yet. Completed orders build up
                    here.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">Reference</th>
                                <th class="px-4 py-2 font-medium">Status</th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Amount
                                </th>
                                <th class="px-4 py-2 font-medium">Sent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in payouts"
                                :key="line.reference"
                                class="border-t"
                            >
                                <td class="px-4 py-2 font-mono text-xs">
                                    {{ line.reference }}
                                </td>
                                <td class="px-4 py-2">
                                    <Badge :variant="toneFor(line.status)">
                                        {{ line.statusLabel }}
                                    </Badge>
                                    <p
                                        v-if="line.failureReason"
                                        class="text-muted-foreground mt-1 text-xs"
                                    >
                                        {{ line.failureReason }}
                                    </p>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <Money :amount="line.amountNgwee" />
                                </td>
                                <td
                                    class="text-muted-foreground px-4 py-2 text-xs"
                                >
                                    {{ line.settledAt ?? line.sentAt ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Alert>
            <Info class="size-4" aria-hidden="true" />
            <AlertDescription>
                MonaFind pays the gateway's transfer charge. What you see here
                is what reaches your account.
            </AlertDescription>
        </Alert>
    </div>
</template>
