<script setup lang="ts">
import { ScanEye, ShieldCheck } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { InspectionBadge } from '@/types';

/**
 * The inspection badge: whether MonaFind staff have looked at the part.
 *
 * An uninspected listing is labelled rather than left blank. A buyer who sees
 * no badge cannot tell whether the part was checked and passed or never
 * checked at all, and removing that ambiguity is the whole reason the badge
 * exists.
 *
 * Use ListingBadges rather than this component directly.
 */
defineProps<{ inspection: InspectionBadge }>();
</script>

<template>
    <Badge
        :variant="inspection.variant"
        :title="inspection.description"
        :data-inspection="inspection.value"
    >
        <component
            :is="inspection.inspected ? ShieldCheck : ScanEye"
            aria-hidden="true"
        />
        {{ inspection.label }}
        <span class="sr-only">— {{ inspection.description }}</span>
    </Badge>
</template>
