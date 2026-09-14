<script setup lang="ts">
import { Scale } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useCompare, MAX_COMPARED } from '@/composables/useCompare';
import type { ProductCard } from '@/types';

/**
 * Add a listing to the compare drawer.
 *
 * Entirely client-side — no request, no account. Comparing four brake discs
 * is a decision somebody makes in one sitting, and asking them to log in
 * first would be asking for something the feature does not need.
 */
const props = defineProps<{
    listing: ProductCard;
    /** Icon-only on a card; labelled on the listing page. */
    variant?: 'icon' | 'labelled';
}>();

const { has, toggle, isFull, drawerOpen } = useCompare();

const isCompared = computed(() => has(props.listing.id));

/** Full, and this one is not in it: nothing to do until something comes out. */
const isBlocked = computed(() => isFull.value && !isCompared.value);

const label = computed(() => {
    if (isCompared.value) {
        return 'Remove from comparison';
    }

    return isBlocked.value
        ? `Comparing ${MAX_COMPARED} already — remove one first`
        : 'Add to comparison';
});

const press = (): void => {
    const added = toggle(props.listing);

    /* Opening the drawer on the second item is when it becomes useful. */
    if (added) {
        drawerOpen.value = true;
    }
};
</script>

<template>
    <Button
        type="button"
        :variant="
            isCompared
                ? 'secondary'
                : variant === 'labelled'
                  ? 'outline'
                  : 'ghost'
        "
        :size="variant === 'labelled' ? 'default' : 'icon'"
        :aria-pressed="isCompared"
        :aria-label="label"
        :title="label"
        :disabled="isBlocked"
        :class="
            variant === 'labelled'
                ? ''
                : 'bg-background/80 hover:bg-background size-9 rounded-full backdrop-blur'
        "
        @click.stop.prevent="press"
    >
        <Scale class="size-4" aria-hidden="true" />
        <span v-if="variant === 'labelled'">
            {{ isCompared ? 'Comparing' : 'Compare' }}
        </span>
    </Button>
</template>
