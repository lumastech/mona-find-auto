<script setup lang="ts">
import { BadgeCheck, ShieldQuestionMark } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';

/**
 * The Verified / Not yet verified badge.
 *
 * Unverified sellers are labelled rather than left blank: a buyer who sees no
 * badge cannot tell whether the shop was checked and passed or never checked
 * at all, and that ambiguity is the thing the badge exists to remove.
 */
withDefaults(
    defineProps<{
        verified: boolean;
        label?: string;
    }>(),
    { label: undefined },
);
</script>

<template>
    <Badge :variant="verified ? 'default' : 'outline'">
        <component
            :is="verified ? BadgeCheck : ShieldQuestionMark"
            aria-hidden="true"
        />
        {{ label ?? (verified ? 'Verified' : 'Not yet verified') }}
    </Badge>
</template>
