<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import reference from '@/routes/admin/reference';
import type {
    CategoryRow,
    LabelledValue,
    ReferenceMake,
    VehicleModelOption,
} from '@/types';

/**
 * The reference lists staff curate.
 *
 * These are what stop "Toyota", "TOYOTA" and "Toyata" becoming three makes
 * and the search facets becoming useless. Nothing here deletes: a make with
 * listings behind it is retired instead, which takes it out of every picker
 * and leaves the listings that point at it intact.
 */
const props = defineProps<{
    makes: ReferenceMake[];
    vehicleModels: VehicleModelOption[];
    categories: CategoryRow[];
    bodyTypes: LabelledValue[];
    maxCategoryDepth: number;
    canManage: boolean;
}>();

const tab = ref<'makes' | 'models' | 'categories'>('makes');
const selectedMake = ref<number | ''>(props.makes[0]?.id ?? '');

const modelsForMake = computed(() =>
    props.vehicleModels.filter(
        (model) => String(model.make_id) === String(selectedMake.value),
    ),
);

const parentOptions = computed(() =>
    props.categories.filter((row) => row.depth < props.maxCategoryDepth),
);

const makeName = (id: number): string =>
    props.makes.find((make) => make.id === id)?.name ?? '';
</script>

<template>
    <Head title="Reference data" />

    <div class="space-y-6 p-4">
        <Heading
            title="Reference data"
            description="The makes, models and categories sellers pick from and buyers filter on. Retire what is no longer sold rather than deleting it — listings still point at these rows."
        />

        <div class="flex flex-wrap gap-2">
            <Button
                v-for="option in [
                    { key: 'makes', label: 'Makes' },
                    { key: 'models', label: 'Models' },
                    { key: 'categories', label: 'Categories' },
                ]"
                :key="option.key"
                type="button"
                size="sm"
                :variant="tab === option.key ? 'default' : 'outline'"
                @click="tab = option.key as typeof tab"
            >
                {{ option.label }}
            </Button>
        </div>

        <template v-if="tab === 'makes'">
            <Card v-if="canManage">
                <CardHeader>
                    <CardTitle class="text-base">Add a make</CardTitle>
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="reference.makes.store.form()"
                        v-slot="{ errors, processing }"
                        class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                        reset-on-success
                    >
                        <div class="grid gap-2">
                            <Label for="make-name">Name</Label>
                            <Input id="make-name" name="name" required />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="make-country">Country</Label>
                            <Input id="make-country" name="country" />
                        </div>

                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            <Plus v-else class="size-4" aria-hidden="true" />
                            Add
                        </Button>
                    </Form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">
                        Makes ({{ makes.length }})
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-2">
                    <Form
                        v-for="make in makes"
                        :key="make.id"
                        v-bind="reference.makes.update.form(make.slug)"
                        v-slot="{ processing }"
                        class="grid items-center gap-3 border-b py-2 last:border-0 sm:grid-cols-[1fr_1fr_auto_auto_auto]"
                    >
                        <Input name="name" :default-value="make.name" />
                        <Input
                            name="country"
                            :default-value="make.country ?? ''"
                        />

                        <label class="flex items-center gap-2 text-sm">
                            <Checkbox
                                name="is_popular"
                                :default-value="make.is_popular"
                            />
                            Popular
                        </label>

                        <label class="flex items-center gap-2 text-sm">
                            <Checkbox
                                name="is_active"
                                :default-value="make.is_active"
                            />
                            Active
                        </label>

                        <div class="flex items-center gap-2">
                            <Badge variant="outline">
                                {{ make.vehicle_models_count ?? 0 }} models
                            </Badge>
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                :disabled="processing || !canManage"
                            >
                                Save
                            </Button>
                        </div>
                    </Form>
                </CardContent>
            </Card>
        </template>

        <template v-else-if="tab === 'models'">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Models by make</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid max-w-xs gap-2">
                        <Label for="make-filter">Make</Label>
                        <select
                            id="make-filter"
                            v-model="selectedMake"
                            class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        >
                            <option
                                v-for="make in makes"
                                :key="make.id"
                                :value="make.id"
                            >
                                {{ make.name }}
                            </option>
                        </select>
                    </div>

                    <Form
                        v-if="canManage && selectedMake"
                        v-bind="reference.vehicleModels.store.form()"
                        v-slot="{ errors, processing }"
                        class="grid gap-4 rounded-lg border p-4 sm:grid-cols-[1fr_120px_120px_auto] sm:items-end"
                        reset-on-success
                    >
                        <input
                            type="hidden"
                            name="make_id"
                            :value="selectedMake"
                        />

                        <div class="grid gap-2">
                            <Label for="model-name">
                                New model for
                                {{ makeName(Number(selectedMake)) }}
                            </Label>
                            <Input id="model-name" name="name" required />
                            <InputError :message="errors.name || errors.slug" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="model-start">From</Label>
                            <Input
                                id="model-start"
                                name="production_start_year"
                                type="number"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="model-end">To</Label>
                            <Input
                                id="model-end"
                                name="production_end_year"
                                type="number"
                                placeholder="ongoing"
                            />
                        </div>

                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            Add model
                        </Button>
                    </Form>

                    <ul class="space-y-2">
                        <li
                            v-for="model in modelsForMake"
                            :key="model.id"
                            class="flex flex-wrap items-center gap-3 border-b py-2 text-sm last:border-0"
                        >
                            <span class="font-medium">{{ model.name }}</span>
                            <span class="text-muted-foreground">
                                {{ model.production_start_year ?? '?' }}–{{
                                    model.production_end_year ?? 'now'
                                }}
                            </span>
                            <Badge v-if="!model.is_active" variant="outline">
                                Retired
                            </Badge>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </template>

        <template v-else>
            <Card v-if="canManage">
                <CardHeader>
                    <CardTitle class="text-base">Add a category</CardTitle>
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="reference.categories.store.form()"
                        v-slot="{ errors, processing }"
                        class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                        reset-on-success
                    >
                        <div class="grid gap-2">
                            <Label for="category-name">Name</Label>
                            <Input id="category-name" name="name" required />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="category-parent">Sits under</Label>
                            <select
                                id="category-parent"
                                name="parent_id"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option value="">Top level</option>
                                <option
                                    v-for="row in parentOptions"
                                    :key="row.id"
                                    :value="row.id"
                                >
                                    {{ '— '.repeat(row.depth) }}{{ row.name }}
                                </option>
                            </select>
                            <InputError :message="errors.parent_id" />
                        </div>

                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            Add
                        </Button>
                    </Form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">
                        Category tree ({{ categories.length }})
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="row in categories"
                            :key="row.id"
                            class="flex items-center gap-2 border-b py-1.5 last:border-0"
                            :style="{ paddingLeft: `${row.depth * 20}px` }"
                        >
                            <span :class="{ 'font-medium': row.depth === 0 }">
                                {{ row.name }}
                            </span>
                            <Badge v-if="!row.selectable" variant="outline">
                                Heading
                            </Badge>
                            <Badge v-if="!row.is_active" variant="outline">
                                Hidden
                            </Badge>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
