<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { ListingStatusBadge, ListingStatusOption } from '@/types';

/**
 * Where a listing is in its lifecycle, for the seller portal and the staff
 * console.
 *
 * Never shown on the storefront: a buyer only ever sees published listings,
 * so a status chip there would only ever say one thing.
 */
defineProps<{ status: ListingStatusBadge | ListingStatusOption }>();
</script>

<template>
    <Badge
        :variant="status.variant"
        :title="status.guidance"
        :data-status="status.value"
    >
        {{ status.label }}
    </Badge>
</template>
