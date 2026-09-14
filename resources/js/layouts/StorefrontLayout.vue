<script setup lang="ts">
import AnnouncementBanner from '@/components/AnnouncementBanner.vue';
import CompareDrawer from '@/components/shopping/CompareDrawer.vue';
import StorefrontFooter from '@/components/storefront/StorefrontFooter.vue';
import StorefrontHeader from '@/components/storefront/StorefrontHeader.vue';
import { Toaster } from '@/components/ui/sonner';

/**
 * The guest and buyer facing shell, mounted at /.
 *
 * Guests reach every page here; only contact details and checkout ask them
 * to log in.
 *
 * The compare drawer lives in the shell rather than on the search page,
 * because comparing is something a buyer does *across* pages — add one from a
 * search result, another from a category, a third from a seller's shopfront.
 * It holds its listings in sessionStorage and shows nothing until there is
 * something in it.
 */
/*
 * No defaults here on purpose. The header falls back to the shared `shopping`
 * prop when these are undefined, and a default of 0 is not undefined — it
 * would win the fallback and pin both badges at zero on every page.
 */
defineProps<{
    cartCount?: number;
    wishlistCount?: number;
}>();
</script>

<template>
    <div class="bg-background text-foreground flex min-h-screen flex-col">
        <!-- Above the header: a banner below the fold is one nobody reads. -->
        <AnnouncementBanner />

        <StorefrontHeader
            :cart-count="cartCount"
            :wishlist-count="wishlistCount"
        />

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 sm:py-8">
            <slot />
        </main>

        <StorefrontFooter />
        <CompareDrawer />
        <Toaster />
    </div>
</template>
