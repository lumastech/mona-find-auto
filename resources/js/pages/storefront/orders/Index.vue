<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { PackageOpen } from '@lucide/vue';
import Money from '@/components/Money.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import orders from '@/routes/orders';
import { home } from '@/routes';
import type { LabelledOption, OrderSummary } from '@/types';

/**
 * The buyer's orders.
 *
 * One row per seller's order rather than per payment. A buyer who paid once
 * for parts from four shops has four things to chase, four statuses and four
 * people to ring — showing them as a single "order" would hide the only
 * information they came here for.
 */
const props = defineProps<{
    orders: {
        data: OrderSummary[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { status: string | null };
    statuses: LabelledOption[];
}>();

const filterBy = (status: string | null): void => {
    router.get(orders.index().url, status ? { status } : {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};
</script>

<template>
    <Head title="Your orders" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Your orders</h1>
            <p class="text-muted-foreground text-sm">
                One order per seller. Each one moves at its own pace.
            </p>
        </header>

        <div class="flex flex-wrap gap-2">
            <Button
                :variant="filters.status ? 'outline' : 'secondary'"
                size="sm"
                @click="filterBy(null)"
            >
                All
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

        <Card v-if="!props.orders.data.length">
            <CardContent class="flex flex-col items-center gap-3 py-12">
                <PackageOpen
                    class="text-muted-foreground size-8"
                    aria-hidden="true"
                />
                <p class="text-muted-foreground text-sm">No orders here yet.</p>
                <Button as-child>
                    <Link :href="home()">Find a part</Link>
                </Button>
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
                                :href="orders.show(order.number).url"
                                class="font-medium hover:underline"
                            >
                                {{ order.number }}
                            </Link>
                            <OrderStatusBadge :order="order" />
                        </div>
                        <p class="text-muted-foreground text-sm">
                            {{ order.seller.business_name }} ·
                            {{ order.fulfilment_label }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ order.status_description }}
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="font-semibold">
                            <Money :amount="order.total_ngwee" />
                        </p>
                        <Button as-child variant="ghost" size="sm">
                            <Link :href="orders.show(order.number).url"
                                >View</Link
                            >
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
