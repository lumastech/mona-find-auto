<script setup lang="ts">
import { Package, PackageOpen, Wrench } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { ConditionBadge } from '@/types';

/**
 * The condition badge: Brand New, Used, or Car Breaker.
 *
 * This says what the part *is*. It is not a quality judgement and it is not
 * MonaFind's opinion — that is the inspection badge, which always sits beside
 * it. Use ListingBadges rather than this component directly, so the pair can
 * never come apart.
 */
const props = defineProps<{ condition: ConditionBadge }>();

const icon = computed(() => {
    switch (props.condition.value) {
        case 'brand_new':
            return Package;
        case 'car_breaker':
            return Wrench;
        default:
            return PackageOpen;
    }
});
</script>

<template>
    <Badge
        :variant="condition.variant"
        :title="condition.description"
        :data-condition="condition.value"
    >
        <component :is="icon" aria-hidden="true" />
        {{ condition.label }}
    </Badge>
</template>
