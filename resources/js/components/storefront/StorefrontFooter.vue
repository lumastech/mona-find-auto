<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import categories from '@/routes/categories';
import { home, search } from '@/routes';
import mechanics from '@/routes/mechanics';
import pages from '@/routes/pages';
import sellers from '@/routes/sellers';
import type { NavCategory, NavMake } from '@/types';

/**
 * The footer, and the site's second navigation.
 *
 * Four columns rather than a copyright line, because on a marketplace the
 * footer is where a crawler finds the catalogue and where a buyer who has
 * scrolled to the bottom of a page without finding their part gets another
 * way in. The category and make columns are cut from the same shared `nav`
 * prop the header uses, so they cannot drift apart.
 */
const page = usePage();
const year = new Date().getFullYear();
const currency = computed(() => page.props.platform.currency.code);

/* Six headings is what fits without the footer becoming a second homepage. */
const topCategories = computed<NavCategory[]>(
    () => page.props.nav?.categories.slice(0, 6) ?? [],
);

const popularMakes = computed<NavMake[]>(
    () =>
        page.props.nav?.makes.filter((make) => make.is_popular).slice(0, 8) ??
        [],
);

const company = [
    { label: 'About MonaFindAuto', slug: 'about' },
    { label: 'Frequently asked questions', slug: 'faq' },
    { label: 'Contact us', slug: 'contact' },
];

const legal = [
    { label: 'Terms of use', slug: 'terms' },
    { label: 'Privacy notice', slug: 'privacy' },
];
</script>

<template>
    <footer class="bg-brand-navy text-brand-navy-foreground mt-16">
        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6">
            <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                <div class="flex flex-col gap-3">
                    <Link
                        :href="home()"
                        class="font-display flex items-center gap-2 text-lg font-semibold tracking-tight"
                    >
                        <span
                            class="bg-brand-gold font-display text-brand-navy grid size-8 shrink-0 place-items-center rounded-md text-base font-bold"
                            aria-hidden="true"
                        >
                            M
                        </span>
                        MonaFind<span class="text-brand-gold -ml-2">Auto</span>
                    </Link>
                    <p class="text-sm text-white/70">
                        Vehicle parts from verified Zambian sellers. Find the
                        part, check the seller, pay safely.
                    </p>
                    <p
                        class="text-brand-gold mt-1 flex items-start gap-2 text-sm"
                    >
                        <ShieldCheck
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Payments are held until you confirm the part is
                            right.
                        </span>
                    </p>
                </div>

                <nav class="flex flex-col gap-2" aria-label="Browse parts">
                    <h2
                        class="font-display text-xs font-semibold tracking-[0.14em] text-white/50 uppercase"
                    >
                        Browse parts
                    </h2>
                    <Link
                        v-for="category in topCategories"
                        :key="category.id"
                        :href="categories.show(category.slug).url"
                        class="text-sm text-white/80 underline-offset-4 hover:text-white hover:underline"
                    >
                        {{ category.name }}
                    </Link>
                    <Link
                        :href="categories.index()"
                        class="text-sm text-white/80 underline-offset-4 hover:text-white hover:underline"
                    >
                        All categories
                    </Link>
                </nav>

                <nav class="flex flex-col gap-2" aria-label="Browse by make">
                    <h2
                        class="font-display text-xs font-semibold tracking-[0.14em] text-white/50 uppercase"
                    >
                        By vehicle
                    </h2>
                    <Link
                        v-for="make in popularMakes"
                        :key="make.id"
                        :href="search.url({ query: { make_id: make.id } })"
                        class="text-sm text-white/80 underline-offset-4 hover:text-white hover:underline"
                    >
                        {{ make.name }} parts
                    </Link>
                </nav>

                <nav class="flex flex-col gap-2" aria-label="Company">
                    <h2
                        class="font-display text-xs font-semibold tracking-[0.14em] text-white/50 uppercase"
                    >
                        MonaFind
                    </h2>
                    <Link
                        :href="sellers.register()"
                        class="text-sm text-white/80 underline-offset-4 hover:text-white hover:underline"
                    >
                        Sell on MonaFindAuto
                    </Link>
                    <Link
                        :href="mechanics.index()"
                        class="text-sm text-white/80 underline-offset-4 hover:text-white hover:underline"
                    >
                        Find a mechanic
                    </Link>
                    <Link
                        v-for="item in company"
                        :key="item.slug"
                        :href="pages.show(item.slug).url"
                        class="text-sm text-white/80 underline-offset-4 hover:text-white hover:underline"
                    >
                        {{ item.label }}
                    </Link>
                </nav>
            </div>

            <div
                class="mt-10 flex flex-col gap-3 border-t border-white/10 pt-6 text-sm text-white/60 sm:flex-row sm:items-center sm:justify-between"
            >
                <p>
                    &copy; {{ year }} MonaFindAuto. All prices in
                    {{ currency }} and include VAT.
                </p>
                <p class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <Link
                        v-for="item in legal"
                        :key="item.slug"
                        :href="pages.show(item.slug).url"
                        class="underline-offset-4 hover:text-white hover:underline"
                    >
                        {{ item.label }}
                    </Link>
                </p>
            </div>
        </div>
    </footer>
</template>
