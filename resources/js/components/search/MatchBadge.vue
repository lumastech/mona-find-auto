<script setup lang="ts">
import { HelpCircle } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { MatchBadge } from '@/types';

/**
 * Why this listing is on this page.
 *
 * A buyer who searched for Hilux brake pads and is looking at a Corolla part
 * deserves to be told, in the row itself, rather than being left to work out
 * that the platform loosened their search for them. The tooltip is the long
 * answer; the badge is the short one.
 *
 * An exact match says nothing at all. A badge on every result that reads
 * "this is what you asked for" is noise, and it would make the ones that
 * matter invisible.
 */
const { match } = defineProps<{ match: MatchBadge }>();
</script>

<template>
    <TooltipProvider v-if="match.tier !== 'exact'" :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="focus-visible:ring-ring rounded-full focus-visible:ring-2 focus-visible:outline-none"
                    :aria-label="`Why am I seeing this? ${match.explanation}`"
                >
                    <Badge variant="outline" class="gap-1 font-normal">
                        {{ match.label }}
                        <HelpCircle class="size-3" aria-hidden="true" />
                    </Badge>
                </button>
            </TooltipTrigger>
            <TooltipContent class="max-w-64">
                <p class="font-medium">Why am I seeing this?</p>
                <p>{{ match.explanation }}</p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
