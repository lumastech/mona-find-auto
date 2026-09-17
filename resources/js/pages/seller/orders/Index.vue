<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Clock, Inbox } from '@lucide/vue';
import { computed } from 'vue';
import Money from '@/components/Money.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import sellerOrders from '@/routes/seller/orders';
import type { LabelledOption, OrderSummary } from '@/types';

/**
 * The shop's order inbox.
 *
 * Opens on Paid, and that is not a default so much as the point of the
 * screen: those are the orders counting down towards auto-cancellation, and a
 * seller who opens their portal onto an archive is a seller whose orders get
 * cancelled and whose buyers get refunded.
 *
 * Each tab carries its count so the size of the work is visible before it is
 * clicked into.
 */
const props = defineProps<{
    orders: {
        data: OrderSummary[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { status: string | null; search: string | null };
    statuses: LabelledOption[];
    counts: Record<string, number>;
}>();

const awaiting = computed(() => props.counts.paid ?? 0);

const apply = (changes: Record<string, string | null>): void => {
    router.get(
        sellerOrders.index().url,
        { ...props.filters, ...changes },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head title="Orders" />

    <div class="space-y-6 px-4 sm:px-6 lg:px-8">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Orders</h1>
            <p class="text-muted-foreground text-sm">
                <span v-if="awaiting">
                    {{ awaiting }} paid
                    {{ awaiting === 1 ? 'order needs' : 'orders need' }} your
                    confirmation.
                </span>
                <span v-else>Nothing is waiting on you.</span>
            </p>
        </header>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                :variant="filters.status ? 'outline' : 'secondary'"
                size="sm"
                @click="apply({ status: null })"
            >
                All ({{ props.orders.total }})
            </Button>

            <Button
                v-for="status in statuses"
                :key="status.value"
                :variant="
                    filters.status === status.value ? 'secondary' : 'outline'
                "
                size="sm"
                @click="apply({ status: status.value })"
            >
                {{ status.label }}
                <Badge v-if="counts[status.value]" variant="outline">
                    {{ counts[status.value] }}
                </Badge>
            </Button>

            <Input
                class="w-56"
                placeholder="Order number or buyer"
                :default-value="filters.search ?? ''"
                @change="
                    apply({ search: ($event.target as HTMLInputElement).value })
                "
            />
        </div>

        <Card v-if="!props.orders.data.length">
            <CardContent class="flex flex-col items-center gap-3 py-12">
                <Inbox
                    class="text-muted-foreground size-8"
                    aria-hidden="true"
                />
                <p class="text-muted-foreground text-sm">
                    No orders match this filter.
                </p>
            </CardContent>
        </Card>

        <div v-else class="space-y-3">
            <Card v-for="order in props.orders.data" :key="order.number">
                <CardContent
                    class="flex flex-wrap items-center justify-between gap-4 py-4"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="sellerOrders.show(order.number).url"
                                class="font-medium hover:underline"
                            >
                                {{ order.number }}
                            </Link>
                            <OrderStatusBadge :order="order" />
                            <Badge variant="outline">{{
                                order.fulfilment_label
                            }}</Badge>
                        </div>
                        <p class="text-muted-foreground text-sm">
                            {{ order.items?.length ?? 0 }}
                            {{
                                (order.items?.length ?? 0) === 1
                                    ? 'line'
                                    : 'lines'
                            }}
                            <span v-if="order.paid_at">
                                · paid
                                {{
                                    new Date(order.paid_at).toLocaleDateString()
                                }}
                            </span>
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="font-semibold">
                            <Money :amount="order.total_ngwee" />
                        </p>
                        <p
                            v-if="order.status === 'paid'"
                            class="text-muted-foreground flex items-center justify-end gap-1 text-xs"
                        >
                            <Clock class="size-3" aria-hidden="true" />
                            Confirm within 24 hours
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
