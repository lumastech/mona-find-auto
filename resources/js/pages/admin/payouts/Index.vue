<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

/**
 * Payout runs.
 *
 * Building a batch from here is safe and deliberately so: it only computes
 * who is owed what and stops. Nothing on this screen moves money — that takes
 * a second person on the batch's own page.
 */
defineProps<{
    batches: Array<{
        reference: string;
        status: string;
        statusLabel: string;
        lineCount: number;
        totalNgwee: number;
        paidNgwee: number;
        failedNgwee: number;
        preparedBy: string | null;
        approvedBy: string | null;
        scheduledFor: string | null;
        url: string;
    }>;
    pagination: { currentPage: number; lastPage: number; total: number };
    statuses: Array<{ value: string; label: string }>;
}>();

function toneFor(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'completed') {
        return 'default';
    }

    if (status === 'failed' || status === 'cancelled') {
        return 'destructive';
    }

    return 'secondary';
}
</script>

<template>
    <Head title="Payouts" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Payouts</h1>
                <p class="text-muted-foreground mt-1 text-sm">
                    A run is built by one person and released by another.
                </p>
            </div>

            <Button @click="router.post('/admin/payouts')">
                <Plus class="size-4" aria-hidden="true" />
                Build a batch
            </Button>
        </div>

        <Card>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Reference</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Sellers
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Total
                            </th>
                            <th class="px-4 py-2 font-medium">Prepared</th>
                            <th class="px-4 py-2 font-medium">Approved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="batch in batches"
                            :key="batch.reference"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                <Link
                                    :href="batch.url"
                                    class="font-medium hover:underline"
                                >
                                    {{ batch.reference }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">
                                <Badge :variant="toneFor(batch.status)">
                                    {{ batch.statusLabel }}
                                </Badge>
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ batch.lineCount }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="batch.totalNgwee" />
                            </td>
                            <td class="text-muted-foreground px-4 py-2 text-xs">
                                {{ batch.preparedBy ?? 'Scheduled' }}
                            </td>
                            <td class="text-muted-foreground px-4 py-2 text-xs">
                                {{ batch.approvedBy ?? '—' }}
                            </td>
                        </tr>
                        <tr v-if="batches.length === 0">
                            <td
                                colspan="6"
                                class="text-muted-foreground px-4 py-8 text-center"
                            >
                                No payout runs yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
