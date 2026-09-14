<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SearchBar from '@/components/storefront/SearchBar.vue';
import categoryRoutes from '@/routes/categories';
import { home } from '@/routes';

/**
 * What a visitor sees when a page is missing, forbidden or broken.
 *
 * Inside the storefront shell on purpose: somebody who followed a dead link
 * to a sold listing still has the header, the menu and the search box, which
 * is the difference between a lost visitor and a lost sale. The copy says
 * what happened and what to do next, and never apologises twice.
 */
const { status } = defineProps<{ status: number }>();

const content = computed(() => {
    switch (status) {
        case 403:
            return {
                title: 'You cannot open this page',
                body: 'This page belongs to another account. If you think it should be yours, log in with the account that owns it.',
            };
        case 404:
            return {
                title: 'That page is not here',
                body: 'The listing may have sold, or the address may be wrong. Search for the part and you will probably find another seller with it.',
            };
        case 503:
            return {
                title: 'MonaFindAuto is being updated',
                body: 'We are making a short change and will be back in a few minutes. Nothing in your cart or your orders is affected.',
            };
        default:
            return {
                title: 'Something went wrong at our end',
                body: 'This is our fault, not yours, and we have been told about it. Try again in a moment — any payment you had started is unaffected.',
            };
    }
});
</script>

<template>
    <Head :title="content.title" />

    <div class="mx-auto flex max-w-xl flex-col gap-6 py-10 sm:py-16">
        <p
            class="font-display text-trust-text tabular text-sm font-semibold tracking-[0.14em] uppercase"
        >
            Error {{ status }}
        </p>
        <h1 class="font-display text-3xl font-bold tracking-tight text-balance">
            {{ content.title }}
        </h1>
        <p class="text-muted-foreground">{{ content.body }}</p>

        <SearchBar v-if="status === 404" />

        <div class="flex flex-wrap gap-4 text-sm">
            <Link
                :href="home()"
                class="text-trust-text font-medium underline-offset-4 hover:underline"
            >
                Go to the homepage
            </Link>
            <Link
                :href="categoryRoutes.index()"
                class="text-trust-text font-medium underline-offset-4 hover:underline"
            >
                Browse all parts
            </Link>
        </div>
    </div>
</template>
