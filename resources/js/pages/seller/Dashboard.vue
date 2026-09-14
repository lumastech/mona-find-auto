<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import StockConfirmationBanner from '@/components/inventory/StockConfirmationBanner.vue';
import Money from '@/components/Money.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { StockSummary } from '@/types';

/**
 * Seller portal dashboard. Order and payout figures arrive with the Orders
 * and Finance modules; the stock figures are real.
 *
 * The confirmation banner sits at the top because it is the one thing on this
 * page a seller can act on today, and because listings quietly disappearing
 * from the storefront is the worst surprise the platform can hand a shop.
 */
const stock = computed(
    () => (usePage().props.stock ?? null) as StockSummary | null,
);

const panels = computed(() => [
    { title: 'Awaiting confirmation', value: '0 orders' },
    {
        title: 'Stock needing confirmation',
        value: `${stock.value?.needs_confirmation ?? 0} listings`,
    },
    { title: 'Next payout', money: 0 },
]);
</script>

<template>
    <Head title="Seller dashboard" />

    <div class="space-y-6 px-4 py-6">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                Seller dashboard
            </h1>
            <p class="text-muted-foreground mt-1 text-sm">
                Confirm your stock every few days to keep your listings ranking
                well.
            </p>
        </div>

        <StockConfirmationBanner v-if="stock" :summary="stock" />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card v-for="panel in panels" :key="panel.title">
                <CardHeader>
                    <CardTitle class="text-base">{{ panel.title }}</CardTitle>
                </CardHeader>
                <CardContent class="text-lg font-medium">
                    <Money
                        v-if="panel.money !== undefined"
                        :amount="panel.money"
                    />
                    <span v-else>{{ panel.value }}</span>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
