<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SearchFacets, SearchFilters } from '@/types';

/**
 * The facet filters.
 *
 * Every value carries the count of listings it would leave, and values that
 * would leave none are absent rather than greyed out — on a phone over a slow
 * connection, a list of forty makes that all return nothing is worse than no
 * list at all.
 *
 * Prices are typed in kwacha and travel in ngwee. Nothing here divides by a
 * hundred except the two helpers at the bottom.
 */
const { facets, filters, maxRadiusKm, hasLocation } = defineProps<{
    facets: SearchFacets;
    filters: SearchFilters;
    maxRadiusKm: number;
    hasLocation: boolean;
}>();

const emit = defineEmits<{
    change: [changes: Partial<SearchFilters>];
    clear: [];
    locate: [];
}>();

/** Toggling a chosen value off is how a buyer un-picks it. */
const pick = (key: keyof SearchFilters, value: number | string): void => {
    emit('change', { [key]: filters[key] === value ? undefined : value });
};

const toggle = (key: keyof SearchFilters, checked: boolean): void => {
    emit('change', { [key]: checked ? true : undefined });
};

const kwachaFrom = (ngwee: number | undefined): string =>
    ngwee === undefined ? '' : String(Math.round(ngwee / 100));

const toNgwee = (kwacha: string): number | undefined => {
    const value = Number.parseInt(kwacha, 10);

    return Number.isFinite(value) && value >= 0 ? value * 100 : undefined;
};

const toYear = (value: string): number | undefined => {
    const year = Number.parseInt(value, 10);

    return Number.isFinite(year) && year > 1900 ? year : undefined;
};
</script>

<template>
    <div class="space-y-6" data-slot="facet-sidebar">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">Filter</h2>
            <Button variant="ghost" size="sm" @click="emit('clear')">
                Clear all
            </Button>
        </div>

        <!-- Location. Distance is never in the default order; this is how a
             buyer asks for it to matter at all. -->
        <section class="space-y-2">
            <h3 class="text-xs font-medium tracking-wide uppercase">Near me</h3>
            <Button
                v-if="!hasLocation"
                variant="outline"
                size="sm"
                class="w-full"
                @click="emit('locate')"
            >
                Use my location
            </Button>
            <div v-else class="space-y-1">
                <Label for="facet-radius" class="text-xs">
                    Within {{ filters.radius_km ?? maxRadiusKm }} km
                </Label>
                <input
                    id="facet-radius"
                    type="range"
                    min="1"
                    :max="maxRadiusKm"
                    :value="filters.radius_km ?? maxRadiusKm"
                    class="w-full"
                    @change="
                        emit('change', {
                            radius_km: Number(
                                ($event.target as HTMLInputElement).value,
                            ),
                        })
                    "
                />
            </div>
        </section>

        <section v-if="facets.categories.length" class="space-y-2">
            <h3 class="text-xs font-medium tracking-wide uppercase">
                Category
            </h3>
            <ul class="space-y-1">
                <li v-for="row in facets.categories" :key="row.id">
                    <button
                        type="button"
                        class="hover:bg-accent flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm"
                        :class="
                            filters.category_id === row.id
                                ? 'bg-accent font-medium'
                                : ''
                        "
                        :aria-pressed="filters.category_id === row.id"
                        @click="pick('category_id', row.id)"
                    >
                        <span class="truncate">{{ row.name }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ row.count }}
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <section v-if="facets.makes.length" class="space-y-2">
            <h3 class="text-xs font-medium tracking-wide uppercase">Make</h3>
            <ul class="space-y-1">
                <li v-for="row in facets.makes" :key="row.id">
                    <button
                        type="button"
                        class="hover:bg-accent flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm"
                        :class="
                            filters.make_id === row.id
                                ? 'bg-accent font-medium'
                                : ''
                        "
                        :aria-pressed="filters.make_id === row.id"
                        @click="pick('make_id', row.id)"
                    >
                        <span class="truncate">{{ row.name }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ row.count }}
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <section v-if="facets.vehicle_models.length" class="space-y-2">
            <h3 class="text-xs font-medium tracking-wide uppercase">Model</h3>
            <ul class="space-y-1">
                <li v-for="row in facets.vehicle_models" :key="row.id">
                    <button
                        type="button"
                        class="hover:bg-accent flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm"
                        :class="
                            filters.vehicle_model_id === row.id
                                ? 'bg-accent font-medium'
                                : ''
                        "
                        :aria-pressed="filters.vehicle_model_id === row.id"
                        @click="pick('vehicle_model_id', row.id)"
                    >
                        <span class="truncate">{{ row.name }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ row.count }}
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h3 class="text-xs font-medium tracking-wide uppercase">
                Fits year
            </h3>
            <Input
                id="facet-year"
                type="number"
                inputmode="numeric"
                placeholder="e.g. 2012"
                :model-value="filters.year ?? ''"
                class="h-9"
                @change="
                    emit('change', {
                        year: toYear(($event.target as HTMLInputElement).value),
                    })
                "
            />
        </section>

        <!-- Condition and inspection are independent attributes and are
             filtered independently: a brand-new part can be uninspected. -->
        <section v-if="facets.conditions.length" class="space-y-2">
            <h3 class="text-xs font-medium tracking-wide uppercase">
                Condition
            </h3>
            <ul class="space-y-1">
                <li v-for="row in facets.conditions" :key="row.value">
                    <button
                        type="button"
                        class="hover:bg-accent flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm"
                        :class="
                            filters.condition === row.value
                                ? 'bg-accent font-medium'
                                : ''
                        "
                        :aria-pressed="filters.condition === row.value"
                        @click="pick('condition', row.value)"
                    >
                        <span class="truncate">{{ row.label }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ row.count }}
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <section v-if="facets.sourcing.length" class="space-y-2">
            <h3 class="text-xs font-medium tracking-wide uppercase">
                Part type
            </h3>
            <ul class="space-y-1">
                <li v-for="row in facets.sourcing" :key="row.value">
                    <button
                        type="button"
                        class="hover:bg-accent flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm"
                        :class="
                            filters.sourcing === row.value
                                ? 'bg-accent font-medium'
                                : ''
                        "
                        :aria-pressed="filters.sourcing === row.value"
                        @click="pick('sourcing', row.value)"
                    >
                        <span class="truncate">{{ row.label }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ row.count }}
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h3 class="text-xs font-medium tracking-wide uppercase">
                Price (K)
            </h3>
            <div class="flex items-center gap-2">
                <Input
                    aria-label="Lowest price in kwacha"
                    type="number"
                    inputmode="numeric"
                    placeholder="Min"
                    class="h-9"
                    :model-value="kwachaFrom(filters.min_price)"
                    @change="
                        emit('change', {
                            min_price: toNgwee(
                                ($event.target as HTMLInputElement).value,
                            ),
                        })
                    "
                />
                <span class="text-muted-foreground text-xs">to</span>
                <Input
                    aria-label="Highest price in kwacha"
                    type="number"
                    inputmode="numeric"
                    placeholder="Max"
                    class="h-9"
                    :model-value="kwachaFrom(filters.max_price)"
                    @change="
                        emit('change', {
                            max_price: toNgwee(
                                ($event.target as HTMLInputElement).value,
                            ),
                        })
                    "
                />
            </div>
        </section>

        <section v-if="facets.seller_types.length" class="space-y-2">
            <h3 class="text-xs font-medium tracking-wide uppercase">
                Seller type
            </h3>
            <ul class="space-y-1">
                <li v-for="row in facets.seller_types" :key="row.value">
                    <button
                        type="button"
                        class="hover:bg-accent flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm"
                        :class="
                            filters.seller_type === row.value
                                ? 'bg-accent font-medium'
                                : ''
                        "
                        :aria-pressed="filters.seller_type === row.value"
                        @click="pick('seller_type', row.value)"
                    >
                        <span class="truncate">{{ row.label }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ row.count }}
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <section
            v-if="facets.provinces.length || facets.cities.length"
            class="space-y-2"
        >
            <h3 class="text-xs font-medium tracking-wide uppercase">Where</h3>
            <ul class="space-y-1">
                <li v-for="row in facets.cities" :key="`city-${row.id}`">
                    <button
                        type="button"
                        class="hover:bg-accent flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm"
                        :class="
                            filters.city_id === row.id
                                ? 'bg-accent font-medium'
                                : ''
                        "
                        :aria-pressed="filters.city_id === row.id"
                        @click="pick('city_id', row.id)"
                    >
                        <span class="truncate">{{ row.name }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ row.count }}
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h3 class="text-xs font-medium tracking-wide uppercase">Trust</h3>

            <div class="flex items-center gap-2">
                <Checkbox
                    id="facet-inspected"
                    :model-value="Boolean(filters.inspected)"
                    @update:model-value="toggle('inspected', Boolean($event))"
                />
                <Label for="facet-inspected" class="text-sm font-normal">
                    Inspected by MonaFind
                    <span class="text-muted-foreground">
                        ({{ facets.inspected }})
                    </span>
                </Label>
            </div>

            <div class="flex items-center gap-2">
                <Checkbox
                    id="facet-verified"
                    :model-value="Boolean(filters.verified)"
                    @update:model-value="toggle('verified', Boolean($event))"
                />
                <Label for="facet-verified" class="text-sm font-normal">
                    Verified sellers only
                    <span class="text-muted-foreground">
                        ({{ facets.verified }})
                    </span>
                </Label>
            </div>

            <div class="flex items-center gap-2">
                <Checkbox
                    id="facet-delivery"
                    :model-value="Boolean(filters.delivery)"
                    @update:model-value="toggle('delivery', Boolean($event))"
                />
                <Label for="facet-delivery" class="text-sm font-normal">
                    Delivery available
                    <span class="text-muted-foreground">
                        ({{ facets.delivery }})
                    </span>
                </Label>
            </div>

            <div class="flex items-center gap-2">
                <Checkbox
                    id="facet-in-stock"
                    :model-value="Boolean(filters.in_stock)"
                    @update:model-value="toggle('in_stock', Boolean($event))"
                />
                <Label for="facet-in-stock" class="text-sm font-normal">
                    In stock
                </Label>
            </div>
        </section>
    </div>
</template>
