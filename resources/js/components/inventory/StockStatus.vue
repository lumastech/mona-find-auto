<script setup lang="ts">
import { PackageCheck, PackageX, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { StockLevelBadge, StorefrontFreshness } from '@/types';

/**
 * Availability and confidence, together.
 *
 * Two independent facts, rendered as one block for the same reason the
 * condition and inspection badges are: a buyer needs both to decide whether
 * to cross town. "In stock" from a shop that has confirmed nothing in a week
 * is a weaker claim than "Low stock" from one that confirmed this morning,
 * and showing only the first would quietly overstate it.
 *
 * The freshness label is deliberately absent for fresh and ageing stock —
 * a badge on every listing saying "normal" is noise, and it would make the
 * warning on the others invisible.
 */
const props = withDefaults(
    defineProps<{
        stock: StockLevelBadge;
        freshness?: StorefrontFreshness | null;
        /** Tighter spacing and no icons, for a dense grid. */
        compact?: boolean;
    }>(),
    { freshness: null, compact: false },
);

const icon = computed(() => {
    if (props.stock.value === 'out_of_stock') {
        return PackageX;
    }

    return props.stock.value === 'low_stock' ? TriangleAlert : PackageCheck;
});
</script>

<template>
    <div
        class="flex flex-wrap items-center"
        :class="compact ? 'gap-1' : 'gap-2'"
        data-slot="stock-status"
    >
        <Badge :variant="stock.variant" class="gap-1">
            <component
                :is="icon"
                v-if="!compact"
                class="size-3.5"
                aria-hidden="true"
            />
            {{ stock.label }}
        </Badge>

        <!-- Only when there is something worth warning about. -->
        <Badge
            v-if="freshness?.label"
            :variant="freshness.variant"
            :title="freshness.description"
        >
            {{ freshness.label }}
        </Badge>
    </div>
</template>
