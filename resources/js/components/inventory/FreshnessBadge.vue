<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { SellerFreshness } from '@/types';

/**
 * How long since the seller vouched for this stock, as they see it.
 *
 * The seller's version always renders, unlike the storefront's: on their own
 * stock screen "Stock confirmed" is the reassurance they came for, and the
 * day count beside it is what tells them how much longer that lasts.
 */
defineProps<{ freshness: SellerFreshness }>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-slot="freshness-badge">
        <Badge :variant="freshness.variant" :title="freshness.description">
            {{ freshness.label }}
        </Badge>

        <span class="text-muted-foreground text-xs">
            <template v-if="freshness.days_since_confirmed === 0">
                confirmed today
            </template>
            <template v-else-if="freshness.days_since_confirmed === 1">
                confirmed yesterday
            </template>
            <template v-else>
                {{ freshness.days_since_confirmed }} days ago
            </template>
        </span>
    </div>
</template>
