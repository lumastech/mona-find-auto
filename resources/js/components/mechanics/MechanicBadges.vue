<script setup lang="ts">
import { BadgeCheck, Store } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { MechanicEndorsementBadge } from '@/types';

/**
 * The two-badge model, in one place.
 *
 * "MonaFind approved" is the platform's: staff checked the qualification.
 * "Endorsed by X" is a shop's, one per endorsement, and a mechanic may hold
 * any number. They are drawn differently on purpose — a buyer has to be able
 * to tell at a glance which of the two they are looking at, because they mean
 * very different things about who did the checking.
 */
defineProps<{
    approved: boolean;
    endorsements: MechanicEndorsementBadge[];
    /** The card in a list shows fewer; the profile page shows every one. */
    limit?: number;
}>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <Badge v-if="approved" variant="default" class="gap-1">
            <BadgeCheck class="size-3.5" aria-hidden="true" />
            MonaFind approved
        </Badge>

        <Badge
            v-for="endorsement in limit
                ? endorsements.slice(0, limit)
                : endorsements"
            :key="endorsement.id"
            variant="secondary"
            class="gap-1"
        >
            <Store class="size-3.5" aria-hidden="true" />
            {{ endorsement.label }}
        </Badge>

        <Badge
            v-if="limit && endorsements.length > limit"
            variant="outline"
            class="tabular-nums"
        >
            +{{ endorsements.length - limit }} more
        </Badge>
    </div>
</template>
