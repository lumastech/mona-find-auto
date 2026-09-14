<script setup lang="ts">
import { BadgeCheck, ShieldCheck, Wrench } from '@lucide/vue';
import { computed } from 'vue';

/**
 * The three things MonaFind stands behind.
 *
 * Gold here and nowhere near a seller's own claim — the strip is the page's
 * one statement of what the platform itself guarantees, which is the whole
 * reason the colour means anything on a product card.
 *
 * Counts are optional. A statement with a real number behind it is worth more
 * than one without, and a number the platform cannot honestly give yet is
 * worth less than none — so each claim renders with or without its figure.
 */
const props = defineProps<{
    verifiedSellers?: number;
    inspectedListings?: number;
}>();

/**
 * "Over 200", never "213". A count that ticks up between two page loads
 * reads as a live counter rather than a fact, and the precision buys nothing.
 */
function approximate(value: number | undefined): string | null {
    if (value === undefined || value < 10) {
        return null;
    }

    const magnitude = value >= 100 ? 100 : 10;

    return `Over ${Math.floor(value / magnitude) * magnitude}`;
}

const claims = computed(() => [
    {
        icon: ShieldCheck,
        title: 'Your money is held safely',
        body: 'You pay MonaFind, not the seller. The money is released once you confirm the part is right.',
        figure: null,
    },
    {
        icon: BadgeCheck,
        title: 'Sellers are verified',
        body: 'Every shop, garage, breaker and dealer has their documents checked by MonaFind staff before they can list.',
        figure: approximate(props.verifiedSellers),
    },
    {
        icon: Wrench,
        title: 'Parts can be inspected',
        body: 'Staff physically check listings and badge them. The badge is on the card, before you open it.',
        figure: approximate(props.inspectedListings),
    },
]);
</script>

<template>
    <section class="bg-brand-navy text-brand-navy-foreground rounded-xl">
        <h2 class="sr-only">Why buy through MonaFindAuto</h2>
        <ul class="grid gap-px sm:grid-cols-3">
            <li
                v-for="claim in claims"
                :key="claim.title"
                class="flex flex-col gap-2 p-5 sm:p-6"
            >
                <component
                    :is="claim.icon"
                    class="text-brand-gold size-6"
                    aria-hidden="true"
                />
                <h3 class="font-display text-base font-semibold">
                    {{ claim.title }}
                </h3>
                <p class="text-sm text-white/75">{{ claim.body }}</p>
                <p
                    v-if="claim.figure"
                    class="text-brand-gold mt-auto pt-1 text-sm font-medium"
                >
                    {{ claim.figure }}
                </p>
            </li>
        </ul>
    </section>
</template>
