<script setup lang="ts">
import { ArrowUpDown } from '@lucide/vue';
import { Label } from '@/components/ui/label';
import type { SearchSortValue, SortOption } from '@/types';

/**
 * The sort control, which is on screen whatever the results look like.
 *
 * A buyer who cannot find a way to sort by price assumes there is none, and
 * the cheapest part on the page is the whole reason many of them are here.
 * Nearest first is offered even before we know where the buyer is — choosing
 * it is how they volunteer that.
 */
defineProps<{ options: SortOption[]; hasLocation: boolean }>();

const sort = defineModel<SearchSortValue>({ required: true });
</script>

<template>
    <div class="flex items-center gap-2">
        <ArrowUpDown
            class="text-muted-foreground size-4 shrink-0"
            aria-hidden="true"
        />
        <Label for="search-sort" class="sr-only sm:not-sr-only sm:text-xs">
            Sort by
        </Label>
        <select
            id="search-sort"
            v-model="sort"
            class="border-input bg-background h-9 rounded-md border px-2 text-sm"
        >
            <option
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
                <template v-if="option.needs_location && !hasLocation">
                    (needs your location)
                </template>
            </option>
        </select>
    </div>
</template>
