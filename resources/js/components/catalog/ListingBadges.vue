<script setup lang="ts">
import ConditionBadge from '@/components/catalog/ConditionBadge.vue';
import InspectionBadge from '@/components/catalog/InspectionBadge.vue';
import type {
    ConditionBadge as Condition,
    InspectionBadge as Inspection,
} from '@/types';

/**
 * The two badges every product card and listing page carries.
 *
 * They are rendered together, by one component, because they answer two
 * different questions and neither substitutes for the other: the condition
 * says what the part is, the inspection badge says whether MonaFind has
 * looked at it. A brand-new part can be uninspected and a car-breaker part
 * can be inspected.
 *
 * Rendering them here rather than pairing them by hand on each surface is
 * what stops a card somewhere eventually showing one without the other.
 */
withDefaults(
    defineProps<{
        condition: Condition;
        inspection: Inspection;
        /** Tighter spacing for a dense grid. */
        compact?: boolean;
    }>(),
    { compact: false },
);
</script>

<template>
    <div
        class="flex flex-wrap items-center"
        :class="compact ? 'gap-1' : 'gap-2'"
        data-slot="listing-badges"
    >
        <ConditionBadge :condition="condition" />
        <InspectionBadge :inspection="inspection" />
    </div>
</template>
