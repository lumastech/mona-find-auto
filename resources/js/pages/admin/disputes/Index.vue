<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import adminDisputes from '@/routes/admin/disputes';
import type { DisputeRow, LabelledOption } from '@/types';

/**
 * The dispute queue.
 *
 * Every row is an order that will not complete and money that will not move
 * until somebody decides. It opens on the unresolved ones, oldest first — a
 * queue that opens on the archive is a queue nobody works — and each row
 * carries the money and the shop, because a list of reasons with ids beside
 * them cannot be triaged.
 */
const props = defineProps<{
    disputes: {
        data: DisputeRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { status: string | null };
    statuses: LabelledOption[];
}>();

const filterBy = (status: string | null): void => {
    router.get(adminDisputes.index().url, status ? { status } : {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const waitingSince = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString() : '—';
</script>

<template>
    <Head title="Disputes" />

    <div class="space-y-6 p-4">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Disputes</h1>
            <p class="text-muted-foreground text-sm">
                {{ props.disputes.total }} waiting on a decision. Oldest first.
            </p>
        </header>

        <div class="flex flex-wrap gap-2">
            <Button
                :variant="filters.status ? 'outline' : 'secondary'"
                size="sm"
                @click="filterBy(null)"
            >
                Unresolved
            </Button>
            <Button
                v-for="status in statuses"
                :key="status.value"
                :variant="
                    filters.status === status.value ? 'secondary' : 'outline'
                "
                size="sm"
                @click="filterBy(status.value)"
            >
                {{ status.label }}
            </Button>
        </div>

        <Card v-if="!props.disputes.data.length">
            <CardContent class="flex flex-col items-center gap-3 py-12">
                <ShieldCheck
                    class="text-muted-foreground size-8"
                    aria-hidden="true"
                />
                <p class="text-muted-foreground text-sm">Nothing to decide.</p>
            </CardContent>
        </Card>

        <div v-else class="space-y-3">
            <Card v-for="dispute in props.disputes.data" :key="dispute.id">
                <CardContent
                    class="flex flex-wrap items-start justify-between gap-4 py-4"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="adminDisputes.show(dispute.id).url"
                                class="font-medium hover:underline"
                            >
                                {{ dispute.order.number }}
                            </Link>
                            <Badge :variant="dispute.status_variant">
                                {{ dispute.status_label }}
                            </Badge>
                            <Badge variant="outline">{{
                                dispute.reason_label
                            }}</Badge>
                            <Badge
                                v-if="dispute.covered_by_platform_minimum"
                                variant="secondary"
                            >
                                Platform minimum applies
                            </Badge>
                        </div>

                        <p
                            class="text-muted-foreground line-clamp-2 max-w-prose text-sm"
                        >
                            {{ dispute.details }}
                        </p>

                        <p class="text-muted-foreground text-xs">
                            {{ dispute.opened_by }} vs
                            {{ dispute.order.seller }} · opened
                            {{ waitingSince(dispute.opened_at) }}
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="font-semibold">
                            <Money :amount="dispute.order.total_ngwee" />
                        </p>
                        <Button as-child size="sm" variant="ghost">
                            <Link :href="adminDisputes.show(dispute.id).url"
                                >Decide</Link
                            >
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
