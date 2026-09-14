<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Info, SlidersHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';
import ProductCard from '@/components/catalog/ProductCard.vue';
import AppliedFilters from '@/components/search/AppliedFilters.vue';
import FacetSidebar from '@/components/search/FacetSidebar.vue';
import MatchBadge from '@/components/search/MatchBadge.vue';
import SortControl from '@/components/search/SortControl.vue';
import SearchBar from '@/components/storefront/SearchBar.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import categories from '@/routes/categories';
import { search } from '@/routes';
import type {
    SearchFacets,
    SearchFilters,
    SearchHit,
    SearchLocation,
    SearchSortValue,
    SortOption,
} from '@/types';

/**
 * The search results page.
 *
 * The ordering is decided in the index, not here — exact matches above
 * partial ones, and within those the quality score then price descending. All
 * this page does is show what came back and be honest about it: the match
 * badge on a result that is not what was asked for, the notice above a
 * category fallback, and the chips that let a buyer undo whichever filter
 * emptied their page.
 *
 * Mobile first: on a narrow screen the facets live behind a button and the
 * sort control stays on screen, because a buyer who cannot find a way to sort
 * by price assumes there is none.
 */
const props = defineProps<{
    term: string | null;
    listings: {
        data: SearchHit[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    facets: SearchFacets;
    filters: SearchFilters;
    sort: SearchSortValue;
    sortOptions: SortOption[];
    location: SearchLocation | null;
    notice: string | null;
    fallbackCategory: { id: number; name: string; slug: string } | null;
    maxRadiusKm: number;
}>();

const facetsOpen = ref(false);
const locating = ref(false);

const hasLocation = computed(() => props.location !== null);

const sort = computed<SearchSortValue>({
    get: () => props.sort,
    set: (value) => reload({ sort: value }),
});

/**
 * Every change to the query re-runs the search from page one. Keeping the
 * page number would land a buyer who just narrowed to four results on page
 * three of them, looking at nothing.
 */
function reload(overrides: Record<string, unknown>): void {
    router.get(
        search.url(),
        {
            q: props.term ?? undefined,
            ...props.filters,
            lat: props.location?.lat,
            lng: props.location?.lng,
            sort: props.sort === 'recommended' ? undefined : props.sort,
            ...overrides,
            page: undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const applyFilters = (changes: Partial<SearchFilters>): void => {
    reload(changes);
    facetsOpen.value = false;
};

const removeFilter = (key: keyof SearchFilters): void =>
    reload({ [key]: undefined });

const clearFilters = (): void => {
    router.get(
        search.url({ query: { q: props.term ?? undefined } }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
    facetsOpen.value = false;
};

/**
 * Ask the browser where the buyer is. Nothing happens without this — distance
 * is never part of the default ranking, and a radius filter has nothing to
 * measure from until a buyer volunteers a point.
 */
function locate(): void {
    if (!navigator.geolocation) {
        return;
    }

    locating.value = true;

    navigator.geolocation.getCurrentPosition(
        (position) => {
            locating.value = false;
            reload({
                lat: position.coords.latitude,
                lng: position.coords.longitude,
            });
        },
        () => {
            locating.value = false;
        },
        { maximumAge: 300_000, timeout: 10_000 },
    );
}
</script>

<template>
    <Head :title="term ? `${term} — search` : 'Search parts'">
        <meta name="robots" content="noindex" />
    </Head>

    <div class="space-y-6">
        <div class="md:hidden">
            <SearchBar />
        </div>

        <header class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                <template v-if="term">Results for “{{ term }}”</template>
                <template v-else>Browse parts</template>
            </h1>
            <p class="text-muted-foreground text-sm">
                <template v-if="listings.total > 0">
                    Showing {{ listings.from }}–{{ listings.to }} of
                    {{ listings.total }}
                </template>
                <template v-else>No listings matched.</template>
            </p>
        </header>

        <!-- Tier 3. The buyer asked for something the catalogue does not have,
             and is being shown the nearest category instead — said out loud
             rather than slipped in underneath the results. -->
        <Alert v-if="notice">
            <Info class="size-4" aria-hidden="true" />
            <AlertDescription>
                {{ notice }}
                <Link
                    v-if="fallbackCategory"
                    :href="categories.show(fallbackCategory.slug).url"
                    class="font-medium underline underline-offset-4"
                >
                    Browse all {{ fallbackCategory.name }}
                </Link>
            </AlertDescription>
        </Alert>

        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b pb-3"
        >
            <div class="flex items-center gap-2">
                <Sheet v-model:open="facetsOpen">
                    <SheetTrigger as-child>
                        <Button variant="outline" size="sm" class="lg:hidden">
                            <SlidersHorizontal
                                class="size-4"
                                aria-hidden="true"
                            />
                            Filter
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="bottom"
                        class="max-h-[85vh] overflow-y-auto p-6"
                    >
                        <SheetHeader class="p-0">
                            <SheetTitle>Filter results</SheetTitle>
                        </SheetHeader>
                        <FacetSidebar
                            class="mt-6"
                            :facets="facets"
                            :filters="filters"
                            :max-radius-km="maxRadiusKm"
                            :has-location="hasLocation"
                            @change="applyFilters"
                            @clear="clearFilters"
                            @locate="locate"
                        />
                    </SheetContent>
                </Sheet>

                <AppliedFilters
                    :filters="filters"
                    :facets="facets"
                    @remove="removeFilter"
                />
            </div>

            <SortControl
                v-model="sort"
                :options="sortOptions"
                :has-location="hasLocation"
            />
        </div>

        <div class="lg:grid lg:grid-cols-[16rem_1fr] lg:gap-8">
            <aside class="hidden lg:block">
                <FacetSidebar
                    :facets="facets"
                    :filters="filters"
                    :max-radius-km="maxRadiusKm"
                    :has-location="hasLocation"
                    @change="applyFilters"
                    @clear="clearFilters"
                    @locate="locate"
                />
            </aside>

            <div class="space-y-6">
                <div
                    v-if="listings.data.length"
                    data-test="search-results"
                    class="grid grid-cols-2 gap-4 md:grid-cols-3"
                >
                    <div
                        v-for="listing in listings.data"
                        :key="listing.id"
                        class="space-y-1"
                    >
                        <ProductCard :listing="listing" />
                        <MatchBadge :match="listing.match" />
                    </div>
                </div>

                <div
                    v-else
                    data-test="search-empty"
                    class="rounded-lg border border-dashed p-12 text-center"
                >
                    <p class="font-medium">
                        Nothing matched
                        <template v-if="term">“{{ term }}”</template>.
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Try fewer words, or clear a filter. Part numbers and
                        model names work well.
                    </p>
                    <Button variant="outline" class="mt-4" as-child>
                        <Link :href="categories.index().url">
                            Browse all parts
                        </Link>
                    </Button>
                </div>

                <nav
                    v-if="listings.last_page > 1"
                    aria-label="Search results pages"
                    class="flex flex-wrap items-center justify-center gap-1"
                >
                    <template v-for="link in listings.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="hover:bg-accent rounded-md border px-3 py-1.5 text-sm"
                            :class="
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : ''
                            "
                            :aria-current="link.active ? 'page' : undefined"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="text-muted-foreground px-3 py-1.5 text-sm"
                            v-html="link.label"
                        />
                    </template>
                </nav>
            </div>
        </div>
    </div>
</template>
