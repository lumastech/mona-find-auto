<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ProvinceOption } from '@/types';

/**
 * The province/city/street block every MonaFind address is made of.
 *
 * Province and city cascade: choosing a province narrows the towns, and
 * changing it clears a town that no longer belongs, because the server
 * rejects a mismatched pair and a silent mismatch is worse than an empty
 * field.
 *
 * Plain selects rather than the styled combobox: this renders on low-end
 * Android where the native picker is faster and more familiar.
 */
const props = withDefaults(
    defineProps<{
        provinces: ProvinceOption[];
        errors: Record<string, string | undefined>;
        provinceId?: number | null;
        cityId?: number | null;
        street?: string | null;
        plotNumber?: string | null;
        required?: boolean;
    }>(),
    { required: true },
);

const provinceId = ref<number | null>(props.provinceId ?? null);
const cityId = ref<number | null>(props.cityId ?? null);

const cities = computed(
    () =>
        props.provinces.find((province) => province.id === provinceId.value)
            ?.cities ?? [],
);

watch(provinceId, () => {
    if (!cities.value.some((city) => city.id === cityId.value)) {
        cityId.value = null;
    }
});
</script>

<template>
    <div class="grid gap-6 sm:grid-cols-2">
        <div class="grid gap-2">
            <Label for="province_id">Province</Label>
            <select
                id="province_id"
                v-model.number="provinceId"
                name="province_id"
                :required="required"
                class="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
            >
                <option :value="null" disabled>Select a province</option>
                <option
                    v-for="province in provinces"
                    :key="province.id"
                    :value="province.id"
                >
                    {{ province.name }}
                </option>
            </select>
            <InputError :message="errors.province_id" />
        </div>

        <div class="grid gap-2">
            <Label for="city_id">Town or city</Label>
            <select
                id="city_id"
                v-model.number="cityId"
                name="city_id"
                :required="required"
                :disabled="!provinceId"
                class="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none disabled:opacity-50"
            >
                <option :value="null" disabled>
                    {{
                        provinceId ? 'Select a town' : 'Choose a province first'
                    }}
                </option>
                <option v-for="city in cities" :key="city.id" :value="city.id">
                    {{ city.name }}
                </option>
            </select>
            <InputError :message="errors.city_id" />
        </div>

        <div class="grid gap-2">
            <Label for="street">Street or area</Label>
            <Input
                id="street"
                name="street"
                :default-value="street ?? ''"
                :required="required"
                autocomplete="address-line1"
                placeholder="e.g. Great East Road"
            />
            <InputError :message="errors.street" />
        </div>

        <div class="grid gap-2">
            <Label for="plot_number">
                Plot or house number
                <span class="text-muted-foreground font-normal"
                    >(optional)</span
                >
            </Label>
            <Input
                id="plot_number"
                name="plot_number"
                :default-value="plotNumber ?? ''"
                autocomplete="address-line2"
                placeholder="e.g. 42B"
            />
            <InputError :message="errors.plot_number" />
        </div>
    </div>
</template>
