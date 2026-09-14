<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, BadgeCheck, Clock3, Store } from '@lucide/vue';
import ProductCard from '@/components/catalog/ProductCard.vue';
import MechanicCard from '@/components/mechanics/MechanicCard.vue';
import TrustStrip from '@/components/storefront/TrustStrip.vue';
import VehiclePicker from '@/components/storefront/VehiclePicker.vue';
/* Aliased: the page's own props are called `categories` and `mechanics`. */
import categoryRoutes from '@/routes/categories';
import mechanicRoutes from '@/routes/mechanics';
import sellerRoutes from '@/routes/sellers';
import type { MechanicProfile, ProductCard as ProductCardType } from '@/types';

/**
 * The storefront landing page.
 *
 * The hero is the vehicle picker rather than a photograph: the buyer arriving
 * here almost always knows their car and not the part number, and a picture
 * of an engine bay helps neither. Everything below it is the catalogue
 * introducing itself — what MonaFind stands behind, what is worth browsing,
 * and the two rails that reward the behaviour the platform depends on.
 */
defineProps<{
    categories: Array<{
        id: number;
        name: string;
        slug: string;
        listings: number;
    }>;
    inspected: ProductCardType[];
    freshlyConfirmed: ProductCardType[];
    mechanics: MechanicProfile[];
    stats: {
        verified_sellers: number;
        listings: number;
        inspected: number;
    };
}>();
</script>

<template>
    <Head title="Vehicle parts from verified Zambian sellers" />

    <div class="flex flex-col gap-12 sm:gap-16">
        <section
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,28rem)] lg:items-center"
        >
            <div class="flex flex-col gap-4">
                <h1
                    class="font-display text-3xl font-bold tracking-tight text-balance sm:text-5xl"
                >
                    Find the part. Check the seller. Pay safely.
                </h1>
                <p class="text-muted-foreground max-w-xl text-base sm:text-lg">
                    MonaFindAuto connects Zambian drivers and mechanics with
                    verified parts sellers across the country. Prices include
                    VAT, and your payment is held until you confirm the part is
                    right.
                </p>
                <p
                    v-if="stats.listings > 0"
                    class="text-muted-foreground text-sm"
                >
                    <span class="text-foreground tabular font-medium">
                        {{ stats.listings.toLocaleString() }}
                    </span>
                    parts listed right now.
                </p>
            </div>

            <VehiclePicker />
        </section>

        <section v-if="categories.length > 0">
            <div class="mb-4 flex items-end justify-between gap-4">
                <h2
                    class="font-display text-xl font-semibold tracking-tight sm:text-2xl"
                >
                    Browse by category
                </h2>
                <Link
                    :href="categoryRoutes.index()"
                    class="text-trust-text shrink-0 text-sm font-medium underline-offset-4 hover:underline"
                >
                    All categories
                </Link>
            </div>
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <li v-for="category in categories" :key="category.id">
                    <Link
                        :href="categoryRoutes.show(category.slug).url"
                        class="bg-secondary/60 hover:bg-secondary focus-visible:ring-ring flex h-full flex-col justify-between gap-2 rounded-lg p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <span class="font-display text-sm font-semibold">
                            {{ category.name }}
                        </span>
                        <span class="text-muted-foreground tabular text-xs">
                            {{ category.listings.toLocaleString() }}
                            {{ category.listings === 1 ? 'part' : 'parts' }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <TrustStrip
            :verified-sellers="stats.verified_sellers"
            :inspected-listings="stats.inspected"
        />

        <section v-if="inspected.length > 0">
            <div class="mb-1 flex items-center gap-2">
                <BadgeCheck class="text-trust-text size-5" aria-hidden="true" />
                <h2
                    class="font-display text-xl font-semibold tracking-tight sm:text-2xl"
                >
                    Inspected by MonaFind
                </h2>
            </div>
            <p class="text-muted-foreground mb-4 text-sm">
                Parts our staff have physically checked. The badge stays on the
                card wherever you meet the listing.
            </p>
            <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <li v-for="listing in inspected" :key="listing.id">
                    <ProductCard :listing="listing" />
                </li>
            </ul>
        </section>

        <section v-if="freshlyConfirmed.length > 0">
            <div class="mb-1 flex items-center gap-2">
                <Clock3 class="text-success size-5" aria-hidden="true" />
                <h2
                    class="font-display text-xl font-semibold tracking-tight sm:text-2xl"
                >
                    Stock confirmed recently
                </h2>
            </div>
            <p class="text-muted-foreground mb-4 text-sm">
                Sellers confirm their shelves every few days. These were
                confirmed most recently, so they are the least likely to have
                gone before you arrive.
            </p>
            <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <li v-for="listing in freshlyConfirmed" :key="listing.id">
                    <ProductCard :listing="listing" />
                </li>
            </ul>
        </section>

        <section v-if="mechanics.length > 0">
            <div class="mb-4 flex items-end justify-between gap-4">
                <div>
                    <h2
                        class="font-display text-xl font-semibold tracking-tight sm:text-2xl"
                    >
                        Endorsed mechanics
                    </h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Fitters vouched for by the sellers who work with them.
                    </p>
                </div>
                <Link
                    :href="mechanicRoutes.index()"
                    class="text-trust-text shrink-0 text-sm font-medium underline-offset-4 hover:underline"
                >
                    All mechanics
                </Link>
            </div>
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="mechanic in mechanics" :key="mechanic.id">
                    <MechanicCard :mechanic="mechanic" />
                </li>
            </ul>
        </section>

        <section
            class="bg-secondary flex flex-col items-start gap-4 rounded-xl p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8"
        >
            <div class="max-w-xl">
                <h2
                    class="font-display text-secondary-foreground text-xl font-semibold tracking-tight sm:text-2xl"
                >
                    Selling parts? Reach buyers across Zambia.
                </h2>
                <p class="text-secondary-foreground/80 mt-2 text-sm">
                    Shops, garages, car breakers and dealers list on
                    MonaFindAuto. We verify you, handle the payment, and pay out
                    once the buyer confirms.
                </p>
            </div>
            <Link
                :href="sellerRoutes.register()"
                class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-11 shrink-0 items-center gap-2 rounded-md px-5 text-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
            >
                <Store class="size-4" aria-hidden="true" />
                Start selling
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </section>
    </div>
</template>
