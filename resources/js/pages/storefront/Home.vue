<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BadgeCheck, PackageSearch, ShieldCheck } from '@lucide/vue';
import Money from '@/components/Money.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * Storefront landing page placeholder. The Catalog and Search modules replace
 * the body with real listings.
 */
const highlights = [
    {
        title: 'Verified sellers',
        body: 'Every shop, garage, breaker and dealer is checked by MonaFind staff before they can list.',
        icon: BadgeCheck,
    },
    {
        title: 'Inspected parts',
        body: 'Listings carry an Inspected or Uninspected badge alongside their condition, so you know what you are buying.',
        icon: ShieldCheck,
    },
    {
        title: 'Fresh stock',
        body: 'Sellers confirm stock every few days. Listings that go quiet are demoted and then hidden.',
        icon: PackageSearch,
    },
];
</script>

<template>
    <Head title="Vehicle parts from verified Zambian sellers" />

    <section class="bg-muted/40 rounded-xl px-6 py-10 sm:px-10 sm:py-14">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-4xl">
            Find the part. Check the seller. Pay safely.
        </h1>
        <p class="text-muted-foreground mt-3 max-w-2xl">
            MonaFindAuto connects Zambian drivers and mechanics with verified
            parts sellers. Prices include VAT and payments are held in escrow
            until you confirm the part is right.
        </p>
        <p class="text-muted-foreground mt-6 text-sm">
            Example listing price:
            <Money :amount="129905" class="text-foreground font-medium" />
        </p>
    </section>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Card v-for="highlight in highlights" :key="highlight.title">
            <CardHeader class="flex flex-row items-center gap-3 space-y-0">
                <component
                    :is="highlight.icon"
                    class="text-primary size-5"
                    aria-hidden="true"
                />
                <CardTitle class="text-base">{{ highlight.title }}</CardTitle>
            </CardHeader>
            <CardContent class="text-muted-foreground text-sm">
                {{ highlight.body }}
            </CardContent>
        </Card>
    </section>
</template>
