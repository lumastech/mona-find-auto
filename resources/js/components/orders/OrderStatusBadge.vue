<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { OrderSummary } from '@/types';

/**
 * An order's state, worded and coloured by the server.
 *
 * The label and the variant both come off the payload rather than being
 * mapped here. There is one lifecycle on this platform and it is declared on
 * OrderStatus in PHP; a second opinion in TypeScript is how a buyer ends up
 * reading "Dispatched" on a page the server considers cancelled.
 */
defineProps<{ order: Pick<OrderSummary, 'status_label' | 'status_variant'> }>();
</script>

<template>
    <Badge :variant="order.status_variant">{{ order.status_label }}</Badge>
</template>
