<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search, SlidersHorizontal } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import MechanicCard from '@/components/mechanics/MechanicCard.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    MechanicDirectoryFilters,
    MechanicDirectoryPage,
    MechanicSpeciality,
    ProvinceWithCities,
} from '@/types';

/**
 * The public mechanic directory.
 *
 * Server-rendered and open to guests, like the rest of the storefront. Every
 * profile here has been approved by MonaFind — the server's query is what
 * guarantees that, not anything on this page.
 *
 * Filters are applied by navigating rather than by filtering in the browser,
 * so a filtered directory has a URL somebody can share and come back to.
 */
const props = defineProps<{
    mechanics: MechanicDirectoryPage;
    filters: MechanicDirectoryFilters;
    specialities: MechanicSpeciality[];
    provinces: ProvinceWithCities[];
}>();

const form = reactive({
    search: props.filters.search ?? '',
    speciality_id: props.filters.speciality_id ?? '',
    province_id: props.filters.province_id ?? '',
    city_id: props.filters.city_id ?? '',
    min_rating: props.filters.min_rating ?? '',
    accepting_work: props.filters.accepting_work,
    endorsed_only: props.filters.endorsed_only,
});

const cities = computed(
    () =>
        props.provinces.find(
            (province) => province.id === Number(form.province_id),
        )?.cities ?? [],
);

/* A town in another province is not a filter, it is a contradiction. */
watch(
    () => form.province_id,
    () => {
        if (!cities.value.some((city) => city.id === Number(form.city_id))) {
            form.city_id = '';
        }
    },
);

const apply = () => {
    router.get(
        '/mechanics',
        { ...form },
        { preserveState: true, replace: true },
    );
};

const reset = () => {
    router.get('/mechanics', {}, { replace: true });
};
</script>

<template>
    <Head title="Find a mechanic">
        <meta
            name="description"
            content="Approved and shop-endorsed mechanics across Zambia, by speciality and town."
        />
    </Head>

    <div class="space-y-6">
        <header class="space-y-2">
            <h1 class="text-2xl font-semibold">Find a mechanic</h1>
            <p class="text-muted-foreground max-w-2xl text-sm">
                Every mechanic listed here has had their qualification and work
                history checked by MonaFind. Some also carry endorsements from
                shops they work with.
            </p>
        </header>

        <form
            class="bg-card space-y-4 rounded-lg border p-4"
            @submit.prevent="apply"
        >
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1.5 lg:col-span-2">
                    <Label for="search">Search</Label>
                    <div class="relative">
                        <Search
                            class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                            aria-hidden="true"
                        />
                        <Input
                            id="search"
                            v-model="form.search"
                            class="pl-9"
                            placeholder="Name, or what they do"
                        />
                    </div>
                </div>

                <div class="space-y-1.5">
                    <Label for="speciality">Speciality</Label>
                    <select
                        id="speciality"
                        v-model="form.speciality_id"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    >
                        <option value="">Any speciality</option>
                        <option
                            v-for="speciality in specialities"
                            :key="speciality.id"
                            :value="speciality.id"
                        >
                            {{ speciality.name }}
                        </option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <Label for="min_rating">Minimum rating</Label>
                    <select
                        id="min_rating"
                        v-model="form.min_rating"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    >
                        <option value="">Any rating</option>
                        <option value="4">4 stars and up</option>
                        <option value="3">3 stars and up</option>
                        <option value="2">2 stars and up</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <Label for="province">Province</Label>
                    <select
                        id="province"
                        v-model="form.province_id"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    >
                        <option value="">Anywhere in Zambia</option>
                        <option
                            v-for="province in provinces"
                            :key="province.id"
                            :value="province.id"
                        >
                            {{ province.name }}
                        </option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <Label for="city">Town</Label>
                    <select
                        id="city"
                        v-model="form.city_id"
                        :disabled="!cities.length"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm disabled:opacity-50"
                    >
                        <option value="">Any town</option>
                        <option
                            v-for="city in cities"
                            :key="city.id"
                            :value="city.id"
                        >
                            {{ city.name }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="accepting_work"
                        v-model="form.accepting_work"
                    />
                    <Label for="accepting_work" class="font-normal">
                        Taking work now
                    </Label>
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox id="endorsed_only" v-model="form.endorsed_only" />
                    <Label for="endorsed_only" class="font-normal">
                        Endorsed by a shop
                    </Label>
                </div>

                <div class="ms-auto flex items-center gap-2">
                    <Button type="button" variant="ghost" @click="reset">
                        Clear
                    </Button>
                    <Button type="submit" class="gap-1.5">
                        <SlidersHorizontal class="size-4" aria-hidden="true" />
                        Apply
                    </Button>
                </div>
            </div>
        </form>

        <p class="text-muted-foreground text-sm" aria-live="polite">
            {{ mechanics.total }}
            {{ mechanics.total === 1 ? 'mechanic' : 'mechanics' }} listed
        </p>

        <div
            v-if="mechanics.data.length"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <MechanicCard
                v-for="mechanic in mechanics.data"
                :key="mechanic.id"
                :mechanic="mechanic"
            />
        </div>

        <div v-else class="rounded-lg border border-dashed p-10 text-center">
            <p class="font-medium">No mechanics match that.</p>
            <p class="text-muted-foreground mt-1 text-sm">
                Try a wider area, or clear the speciality filter.
            </p>
        </div>

        <nav
            v-if="mechanics.links.length > 3"
            class="flex flex-wrap justify-center gap-1"
            aria-label="Directory pages"
        >
            <template v-for="link in mechanics.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded-md border px-3 py-1.5 text-sm"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted'
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
</template>
