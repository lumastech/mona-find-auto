<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Heart } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import wishlist from '@/routes/wishlist';
import type { ProductCard } from '@/types';

/**
 * The heart. On every card and every listing page, and prominent.
 *
 * A guest gets the same button rather than a hidden one: the post goes to a
 * guarded route, Laravel sends them to log in, and the intended URL brings
 * them back to the listing they were looking at. Hiding it would mean a buyer
 * finding out the platform wanted an account only once they had made up their
 * mind.
 *
 * The filled state is optimistic. Pressing a heart should feel instant on a
 * low-end Android over a slow connection, so the fill flips immediately and
 * is put back if the request fails.
 */
const props = defineProps<{
    listing: Pick<ProductCard, 'id' | 'slug' | 'name'>;
    /** Icon-only on a card; labelled on the listing page. */
    variant?: 'icon' | 'labelled';
}>();

const page = usePage();

/**
 * Whether this listing is saved, from the ids shared onto every storefront
 * page — so a card does not need a wishlist prop threaded through Catalog,
 * Search and Sellers to know how to draw itself.
 */
const savedOnServer = computed(
    () =>
        page.props.shopping?.saved_product_ids.includes(props.listing.id) ??
        false,
);

/*
 * The local ref is the optimistic layer over that. It is seeded from the
 * server and re-seeded whenever the shared prop changes — which is how a
 * heart pressed on one page is still filled after navigating to another.
 */
const isSaved = ref(savedOnServer.value);
const inFlight = ref(false);

watch(savedOnServer, (saved) => {
    isSaved.value = saved;
});

const label = computed(() =>
    isSaved.value ? 'Saved to wishlist' : 'Save to wishlist',
);

const toggle = (): void => {
    /* A guest is sent to log in by the server; do not fake a saved heart. */
    const optimistic = page.props.auth.user !== null;
    const wasSaved = isSaved.value;

    if (optimistic) {
        isSaved.value = !wasSaved;
    }

    inFlight.value = true;

    const url = wasSaved
        ? wishlist.destroy(props.listing.slug).url
        : wishlist.store(props.listing.slug).url;

    router[wasSaved ? 'delete' : 'post'](
        url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                isSaved.value = wasSaved;
            },
            onFinish: () => {
                inFlight.value = false;
            },
        },
    );
};
</script>

<template>
    <Button
        type="button"
        :variant="variant === 'labelled' ? 'outline' : 'ghost'"
        :size="variant === 'labelled' ? 'default' : 'icon'"
        :aria-pressed="isSaved"
        :aria-label="label"
        :title="label"
        :disabled="inFlight"
        :class="
            variant === 'labelled'
                ? ''
                : 'bg-background/80 hover:bg-background size-9 rounded-full backdrop-blur'
        "
        @click.stop.prevent="toggle"
    >
        <Heart
            class="size-5 transition-colors"
            :class="isSaved ? 'fill-current text-rose-600' : ''"
            aria-hidden="true"
        />
        <span v-if="variant === 'labelled'">
            {{ isSaved ? 'Saved' : 'Save for later' }}
        </span>
    </Button>
</template>
