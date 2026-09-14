<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import Money from '@/components/Money.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import adminOrders from '@/routes/admin/orders';
import type { LabelledOption, OrderSummary } from '@/types';

/**
 * Order search, for staff.
 *
 * Read-only, and deliberately so. Everything staff can actually do to an
 * order happens through the dispute queue, where a reason is mandatory and an
 * audit row is written — a general edit screen here would be the one place on
 * the platform where an order's history could be quietly rearranged.
 */
const props = defineProps<{
    orders: {
        data: OrderSummary[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: {
        search: string | null;
        status: string | null;
        seller_id: number | null;
    };
    statuses: LabelledOption[];
}>();

const apply = (changes: Record<string, string | null>): void => {
    router.get(
        adminOrders.index().url,
        { ...props.filters, ...changes },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head title="Orders" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Orders</h1>
            <p class="text-muted-foreground text-sm">
                {{ props.orders.total }} in total.
            </p>
        </header>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-2 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    class="w-72 pl-8"
                    placeholder="Order number, buyer or seller"
                    :default-value="filters.search ?? ''"
                    @change="
                        apply({
                            search: ($event.target as HTMLInputElement).value,
                        })
                    "
                />
            </div>

            <Button
                :variant="filters.status ? 'outline' : 'secondary'"
                size="sm"
                @click="apply({ status: null })"
            >
                Any status
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
            </Button>
        </div>

        <Card>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Order</th>
                            <th class="px-4 py-2 font-medium">Seller</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Total
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="order in props.orders.data"
                            :key="order.number"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                <Link
                                    :href="adminOrders.show(order.number).url"
                                    class="font-medium hover:underline"
                                >
                                    {{ order.number }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">
                                {{ order.seller.business_name }}
                            </td>
                            <td class="px-4 py-2">
                                <OrderStatusBadge :order="order" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="order.total_ngwee" />
                            </td>
                        </tr>

                        <tr v-if="!props.orders.data.length">
                            <td
                                class="text-muted-foreground px-4 py-8 text-center"
                                colspan="4"
                            >
                                No orders match this search.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
