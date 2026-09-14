<script setup lang="ts">
import { Head, useForm, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { AlertTriangle, Loader2, ShieldCheck } from '@lucide/vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';

/**
 * One payout run, and the moment somebody takes responsibility for it.
 *
 * The approve control is ABSENT rather than disabled for the person who built
 * the batch — `canApprove` comes from the same policy method the server
 * enforces, so the screen and the server can never disagree about who may
 * release money.
 */
const props = defineProps<{
    batch: {
        reference: string;
        status: string;
        statusLabel: string;
        lineCount: number;
        totalNgwee: number;
        paidNgwee: number;
        failedNgwee: number;
        preparedBy: string | null;
        approvedBy: string | null;
        approvalNote: string | null;
        cancellationReason: string | null;
        approvedAt: string | null;
        completedAt: string | null;
        lines: Array<{
            id: number;
            reference: string;
            seller: string;
            status: string;
            statusLabel: string;
            needsAttention: boolean;
            amountNgwee: number;
            feeNgwee: number | null;
            method: string | null;
            destination: string | null;
            blockReason: string | null;
            failureReason: string | null;
        }> | null;
    };
    canApprove: boolean;
    canCancel: boolean;
    progress: {
        live: boolean;
        settled: boolean;
        counts: Record<string, number>;
        outstanding: number;
        needingAttention: number;
        paidNgwee: number;
        failedNgwee: number;
    };
}>();

const approval = useForm({ note: '' });
const cancellation = useForm({ reason: '' });

/**
 * The live execution view.
 *
 * Money out is never retried automatically, so once a batch is approved an
 * operator is watching for one of two endings: every transfer confirmed, or a
 * line that needs a person. Polling stops the moment `progress.settled`
 * arrives — a screen that keeps hitting the server after the run has finished
 * is a screen somebody leaves open all afternoon.
 *
 * Only `progress` is reloaded, not the whole page. A batch of two hundred
 * lines would otherwise re-render two hundred rows every few seconds, which
 * on the low-end Android the brief targets is the difference between a
 * readable screen and an unusable one.
 */
const { start, stop } = usePoll(
    5000,
    { only: ['progress'] },
    { autoStart: props.progress.live },
);

watch(
    () => props.progress.live,
    (live) => (live ? start() : stop()),
);

const statusOrder = [
    'pending',
    'sent',
    'paid',
    'blocked',
    'failed',
    'unresolved',
];

const progressRows = computed(() =>
    statusOrder
        .filter((status) => (props.progress.counts[status] ?? 0) > 0)
        .map((status) => ({
            status,
            count: props.progress.counts[status] ?? 0,
        })),
);

function approve(): void {
    approval.post(`/admin/payouts/${props.batch.reference}/approve`, {
        preserveScroll: true,
    });
}

function cancel(): void {
    cancellation.post(`/admin/payouts/${props.batch.reference}/cancel`, {
        preserveScroll: true,
    });
}

function toneFor(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'paid') {
        return 'default';
    }

    if (status === 'failed' || status === 'blocked') {
        return 'destructive';
    }

    if (status === 'unresolved') {
        return 'outline';
    }

    return 'secondary';
}
</script>

<template>
    <Head :title="`Payout ${batch.reference}`" />

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ batch.reference }}
            </h1>
            <p class="text-muted-foreground mt-1 text-sm">
                Prepared by {{ batch.preparedBy ?? 'the nightly schedule' }}
                <template v-if="batch.approvedBy">
                    · approved by {{ batch.approvedBy }}
                </template>
            </p>
        </div>

        <!--
            Execution status, live while transfers are in flight. Absent
            entirely before a batch is approved: there is nothing to watch.
        -->
        <Card v-if="batch.status !== 'draft' && progressRows.length > 0">
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <span class="font-medium">Execution status</span>
                <span
                    v-if="progress.live"
                    class="text-muted-foreground inline-flex items-center gap-2 text-xs"
                >
                    <Loader2 class="size-3.5 animate-spin" />
                    {{ progress.outstanding }} still in flight · refreshing
                </span>
                <span v-else class="text-muted-foreground text-xs">
                    Run finished
                </span>
            </CardHeader>
            <CardContent class="space-y-3">
                <div class="flex flex-wrap gap-2">
                    <Badge
                        v-for="row in progressRows"
                        :key="row.status"
                        :variant="toneFor(row.status)"
                    >
                        {{ row.count }} {{ row.status }}
                    </Badge>
                </div>
                <p
                    v-if="progress.needingAttention > 0"
                    class="text-destructive text-sm"
                >
                    {{ progress.needingAttention }} line{{
                        progress.needingAttention === 1 ? '' : 's'
                    }}
                    need a person. A transfer that timed out may already have
                    moved money, so nothing here is retried automatically.
                </p>
            </CardContent>
        </Card>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card>
                <CardHeader class="pb-2">
                    <span class="text-muted-foreground text-sm"
                        >Total approved</span
                    >
                </CardHeader>
                <CardContent>
                    <Money
                        :amount="batch.totalNgwee"
                        class="text-2xl font-semibold"
                    />
                    <p class="text-muted-foreground mt-1 text-xs">
                        {{ batch.lineCount }} sellers
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <span class="text-muted-foreground text-sm">Paid</span>
                </CardHeader>
                <CardContent>
                    <Money
                        :amount="batch.paidNgwee"
                        class="text-2xl font-semibold"
                    />
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <span class="text-muted-foreground text-sm"
                        >Failed or blocked</span
                    >
                </CardHeader>
                <CardContent>
                    <Money
                        :amount="batch.failedNgwee"
                        class="text-2xl font-semibold"
                    />
                </CardContent>
            </Card>
        </div>

        <Alert v-if="!canApprove && batch.status === 'awaiting_approval'">
            <ShieldCheck class="size-4" aria-hidden="true" />
            <AlertTitle>Someone else has to release this</AlertTitle>
            <AlertDescription>
                A payout run cannot be approved by the person who prepared it.
            </AlertDescription>
        </Alert>

        <Card v-if="canApprove">
            <CardHeader>
                <h2 class="font-medium">Approve this run</h2>
                <p class="text-muted-foreground text-sm">
                    Releasing sends <Money :amount="batch.totalNgwee" /> to
                    {{ batch.lineCount }} sellers. This cannot be undone.
                </p>
            </CardHeader>
            <CardContent class="space-y-3">
                <div class="grid gap-2">
                    <Label for="note">Note (optional)</Label>
                    <Input
                        id="note"
                        v-model="approval.note"
                        placeholder="Checked against the ledger."
                    />
                    <InputError :message="approval.errors.note" />
                </div>
                <Button :disabled="approval.processing" @click="approve">
                    Approve and send
                </Button>
            </CardContent>
        </Card>

        <Card v-if="canCancel">
            <CardHeader>
                <h2 class="font-medium">Cancel this run</h2>
            </CardHeader>
            <CardContent class="space-y-3">
                <div class="grid gap-2">
                    <Label for="reason">Reason</Label>
                    <Input
                        id="reason"
                        v-model="cancellation.reason"
                        placeholder="Built by mistake."
                    />
                    <InputError :message="cancellation.errors.reason" />
                </div>
                <Button
                    variant="outline"
                    :disabled="cancellation.processing"
                    @click="cancel"
                >
                    Cancel batch
                </Button>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <h2 class="font-medium">Lines</h2>
            </CardHeader>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Seller</th>
                            <th class="px-4 py-2 font-medium">Destination</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Amount
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in batch.lines ?? []"
                            :key="line.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                <p class="font-medium">{{ line.seller }}</p>
                                <p
                                    class="text-muted-foreground font-mono text-xs"
                                >
                                    {{ line.reference }}
                                </p>
                            </td>
                            <td class="text-muted-foreground px-4 py-2 text-xs">
                                {{ line.method }} ·
                                {{ line.destination ?? '—' }}
                            </td>
                            <td class="px-4 py-2">
                                <Badge :variant="toneFor(line.status)">
                                    {{ line.statusLabel }}
                                </Badge>
                                <p
                                    v-if="
                                        line.blockReason || line.failureReason
                                    "
                                    class="text-muted-foreground mt-1 flex items-start gap-1 text-xs"
                                >
                                    <AlertTriangle
                                        v-if="line.needsAttention"
                                        class="mt-0.5 size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {{ line.blockReason ?? line.failureReason }}
                                </p>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="line.amountNgwee" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
