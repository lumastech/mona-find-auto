<script setup lang="ts">
import StarRating from '@/components/ratings/StarRating.vue';
import { Badge } from '@/components/ui/badge';
import type { RatingSummary } from '@/types';

/**
 * The average, and the five bars underneath it.
 *
 * The bars matter more than the average on a marketplace like this one. Four
 * stars from forty people and four stars from two ones and two fives are the
 * same number and completely different shops, and the second is the one a
 * buyer needs to see before sending money.
 */
defineProps<{ summary: RatingSummary; heading?: string }>();

const rows = [5, 4, 3, 2, 1];
</script>

<template>
    <section class="space-y-4" aria-labelledby="rating-summary-heading">
        <h2 id="rating-summary-heading" class="text-lg font-semibold">
            {{ heading ?? 'Buyer reviews' }}
        </h2>

        <div v-if="summary.count === 0" class="text-muted-foreground text-sm">
            No reviews yet. Reviews can only be left by buyers whose order
            completed, so a new shop starts here.
        </div>

        <div v-else class="flex flex-col gap-6 sm:flex-row sm:items-start">
            <div class="flex flex-col items-center gap-1 sm:w-40">
                <p class="text-4xl font-semibold tabular-nums">
                    {{ summary.average?.toFixed(1) }}
                </p>
                <StarRating :model-value="summary.average" size="sm" />
                <p class="text-muted-foreground text-xs">
                    {{ summary.count }}
                    review{{ summary.count === 1 ? '' : 's' }}
                </p>
                <Badge v-if="summary.verified_count > 0" variant="secondary">
                    {{ summary.verified_count }} verified
                </Badge>
            </div>

            <ul class="flex-1 space-y-1.5">
                <li
                    v-for="star in rows"
                    :key="star"
                    class="flex items-center gap-3 text-sm"
                >
                    <span
                        class="text-muted-foreground w-10 shrink-0 tabular-nums"
                    >
                        {{ star }} star
                    </span>
                    <span
                        class="bg-muted h-2 flex-1 overflow-hidden rounded-full"
                        role="img"
                        :aria-label="`${summary.percentages[star] ?? 0}% gave ${star} stars`"
                    >
                        <span
                            class="block h-full rounded-full bg-amber-400"
                            :style="{
                                width: `${summary.percentages[star] ?? 0}%`,
                            }"
                        />
                    </span>
                    <span
                        class="text-muted-foreground w-10 shrink-0 text-right tabular-nums"
                    >
                        {{ summary.breakdown[star] ?? 0 }}
                    </span>
                </li>
            </ul>
        </div>
    </section>
</template>
