<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { formatMoney } from '@/lib/money';
import type { SearchFacets, SearchFilters } from '@/types';

/**
 * The filters currently narrowing the results, each one removable.
 *
 * On a phone the facet sidebar is behind a button, so without these chips a
 * buyer can be looking at four results and have no way of seeing that they
 * asked for brand-new Toyota parts under K200 in Kabwe. Every chip is the
 * shortest route back out of the corner they filtered themselves into.
 */
const { filters, facets } = defineProps<{
    filters: SearchFilters;
    facets: SearchFacets;
}>();

const emit = defineEmits<{ remove: [key: keyof SearchFilters] }>();

/** The reference row's name, or a plain id when it is not on this page of facets. */
const nameOf = (
    list: Array<{ id: number; name: string }>,
    id: number | undefined,
): string | null =>
    id === undefined
        ? null
        : (list.find((row) => row.id === id)?.name ?? `#${id}`);

const labelOf = (
    list: Array<{ value: string; label: string }>,
    value: string | undefined,
): string | null =>
    value === undefined
        ? null
        : (list.find((row) => row.value === value)?.label ?? value);

const chips = computed(() => {
    const entries: Array<{ key: keyof SearchFilters; label: string }> = [];

    const push = (key: keyof SearchFilters, label: string | null): void => {
        if (label !== null) {
            entries.push({ key, label });
        }
    };

    push('category_id', nameOf(facets.categories, filters.category_id));
    push('make_id', nameOf(facets.makes, filters.make_id));
    push(
        'vehicle_model_id',
        nameOf(facets.vehicle_models, filters.vehicle_model_id),
    );
    push('province_id', nameOf(facets.provinces, filters.province_id));
    push('city_id', nameOf(facets.cities, filters.city_id));
    push('condition', labelOf(facets.conditions, filters.condition));
    push('sourcing', labelOf(facets.sourcing, filters.sourcing));
    push('seller_type', labelOf(facets.seller_types, filters.seller_type));

    push('year', filters.year ? `Fits ${filters.year}` : null);
    push('inspected', filters.inspected ? 'Inspected by MonaFind' : null);
    push('verified', filters.verified ? 'Verified sellers' : null);
    push('delivery', filters.delivery ? 'Delivery available' : null);
    push('in_stock', filters.in_stock ? 'In stock' : null);
    push(
        'radius_km',
        filters.radius_km ? `Within ${filters.radius_km} km` : null,
    );

    /* Prices are integer ngwee all the way to the formatter. */
    push(
        'min_price',
        filters.min_price === undefined
            ? null
            : `From ${formatMoney(filters.min_price)}`,
    );
    push(
        'max_price',
        filters.max_price === undefined
            ? null
            : `Up to ${formatMoney(filters.max_price)}`,
    );

    return entries;
});
</script>

<template>
    <ul v-if="chips.length" class="flex flex-wrap items-center gap-2">
        <li v-for="chip in chips" :key="chip.key">
            <button
                type="button"
                class="border-input hover:bg-accent focus-visible:ring-ring inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs focus-visible:ring-2 focus-visible:outline-none"
                @click="emit('remove', chip.key)"
            >
                {{ chip.label }}
                <X class="size-3" aria-hidden="true" />
                <span class="sr-only">Remove this filter</span>
            </button>
        </li>
    </ul>
</template>
