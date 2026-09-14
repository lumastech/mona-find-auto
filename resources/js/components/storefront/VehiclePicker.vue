<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Car, Search, X } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { useSavedVehicle } from '@/composables/useSavedVehicle';
import { Button } from '@/components/ui/button';
import { search } from '@/routes';
import type { NavMake, NavVehicleModel } from '@/types';

/**
 * Make → Model → Year, the way a driver thinks about their car.
 *
 * The other half of the catalogue's front door: the search bar serves the
 * buyer who knows the part, this serves the far larger group who know only
 * the vehicle. Submitting lands on the search page with the three filters
 * applied, which is why nothing here queries anything itself.
 *
 * Native selects rather than the styled listbox on purpose. The primary
 * device is a mid-range Android, where a native select opens the operating
 * system's own wheel — faster to use with a thumb, readable in daylight,
 * accessible without a line of our JavaScript, and rendered by the server
 * along with the rest of the page.
 *
 * The chosen vehicle is remembered on the device, so the next visit starts
 * where this one left off.
 */
const { variant = 'panel' } = defineProps<{
    /** `panel` is the homepage hero; `compact` sits above the facet rail. */
    variant?: 'panel' | 'compact';
}>();

const page = usePage();
const makes = computed<NavMake[]>(() => page.props.nav?.makes ?? []);
const fallbackYears = computed<number[]>(() => page.props.nav?.years ?? []);

const { vehicle, hydrate, remember, forget } = useSavedVehicle();

const makeId = ref<number | null>(null);
const modelId = ref<number | null>(null);
const year = ref<number | null>(null);
const term = ref('');

onMounted(() => {
    hydrate();

    if (vehicle.value !== null) {
        makeId.value = vehicle.value.make_id;
        modelId.value = vehicle.value.vehicle_model_id;
        year.value = vehicle.value.year;
    }
});

const models = computed<NavVehicleModel[]>(
    () => makes.value.find((make) => make.id === makeId.value)?.models ?? [],
);

const selectedModel = computed<NavVehicleModel | null>(
    () => models.value.find((model) => model.id === modelId.value) ?? null,
);

/**
 * A model that declares a production run offers only those years — a 2019
 * Hilux is a real search, a 1974 one is a typo the buyer should not be able
 * to make. Models with no recorded run fall back to the platform range.
 */
const years = computed<number[]>(() => {
    const model = selectedModel.value;

    if (model === null || model.start_year === null) {
        return fallbackYears.value;
    }

    const last = model.end_year ?? fallbackYears.value[0] ?? model.start_year;

    return Array.from(
        { length: Math.max(last - model.start_year + 1, 1) },
        (_, index) => last - index,
    );
});

/* Changing the make invalidates the model beneath it, and the year with it. */
watch(makeId, () => {
    modelId.value = null;
    year.value = null;
});

watch(years, (available) => {
    if (year.value !== null && !available.includes(year.value)) {
        year.value = null;
    }
});

const canSubmit = computed(() => makeId.value !== null);

function submit(): void {
    const make = makes.value.find((candidate) => candidate.id === makeId.value);

    if (make === undefined) {
        return;
    }

    remember({
        make_id: make.id,
        make_name: make.name,
        vehicle_model_id: modelId.value,
        vehicle_model_name: selectedModel.value?.name ?? null,
        year: year.value,
    });

    router.get(
        search.url({
            query: {
                make_id: make.id,
                vehicle_model_id: modelId.value ?? undefined,
                year: year.value ?? undefined,
                q: term.value.trim() === '' ? undefined : term.value.trim(),
            },
        }),
    );
}

function clear(): void {
    forget();
    makeId.value = null;
    modelId.value = null;
    year.value = null;
    term.value = '';
}

const fieldClass =
    'border-input bg-background text-foreground focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60';
</script>

<template>
    <form
        :class="[
            'w-full',
            variant === 'panel'
                ? 'bg-card border-border rounded-xl border p-4 shadow-sm sm:p-5'
                : 'bg-muted/50 border-border rounded-lg border p-3',
        ]"
        @submit.prevent="submit"
    >
        <div class="mb-3 flex items-center gap-2">
            <Car class="text-trust-text size-4 shrink-0" aria-hidden="true" />
            <h2
                :class="[
                    'font-display font-semibold tracking-tight',
                    variant === 'panel' ? 'text-base' : 'text-sm',
                ]"
            >
                Find parts for your vehicle
            </h2>
            <button
                v-if="vehicle !== null"
                type="button"
                class="text-muted-foreground hover:text-foreground ml-auto inline-flex items-center gap-1 text-xs"
                @click="clear"
            >
                <X class="size-3" aria-hidden="true" />
                Forget my vehicle
            </button>
        </div>

        <div
            :class="[
                'grid gap-3',
                variant === 'panel'
                    ? 'sm:grid-cols-2 lg:grid-cols-4'
                    : 'sm:grid-cols-3',
            ]"
        >
            <div class="grid gap-1.5">
                <label
                    for="vehicle-make"
                    class="text-muted-foreground text-xs font-medium"
                >
                    Make
                </label>
                <select
                    id="vehicle-make"
                    v-model="makeId"
                    :class="fieldClass"
                    name="make_id"
                >
                    <option :value="null">Any make</option>
                    <option
                        v-for="make in makes"
                        :key="make.id"
                        :value="make.id"
                    >
                        {{ make.name }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1.5">
                <label
                    for="vehicle-model"
                    class="text-muted-foreground text-xs font-medium"
                >
                    Model
                </label>
                <select
                    id="vehicle-model"
                    v-model="modelId"
                    :class="fieldClass"
                    :disabled="models.length === 0"
                    name="vehicle_model_id"
                >
                    <option :value="null">
                        {{
                            makeId === null
                                ? 'Choose a make first'
                                : 'Any model'
                        }}
                    </option>
                    <option
                        v-for="model in models"
                        :key="model.id"
                        :value="model.id"
                    >
                        {{ model.name }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1.5">
                <label
                    for="vehicle-year"
                    class="text-muted-foreground text-xs font-medium"
                >
                    Year
                </label>
                <select
                    id="vehicle-year"
                    v-model="year"
                    :class="fieldClass"
                    name="year"
                >
                    <option :value="null">Any year</option>
                    <option v-for="value in years" :key="value" :value="value">
                        {{ value }}
                    </option>
                </select>
            </div>

            <div v-if="variant === 'panel'" class="grid gap-1.5">
                <label
                    for="vehicle-part"
                    class="text-muted-foreground text-xs font-medium"
                >
                    Part <span class="font-normal">(optional)</span>
                </label>
                <input
                    id="vehicle-part"
                    v-model="term"
                    type="search"
                    name="q"
                    placeholder="e.g. brake pads"
                    autocomplete="off"
                    :class="fieldClass"
                />
            </div>
        </div>

        <Button
            type="submit"
            :disabled="!canSubmit"
            class="mt-3 h-11 w-full sm:w-auto"
        >
            <Search class="size-4" aria-hidden="true" />
            Show parts
        </Button>
    </form>
</template>
