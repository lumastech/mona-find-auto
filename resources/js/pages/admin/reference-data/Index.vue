<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ExternalLink, Merge, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import referenceData from '@/routes/admin/reference-data';
import type { ReferenceListSummary, ReferenceRow } from '@/types';

/**
 * Every list staff curate, in one place, with the merge tool.
 *
 * The lists come from a registry each module writes to, so this screen never
 * learns what a vehicle make or a town actually is — it renders whatever was
 * registered. Adding a seventh list is a change in the module that owns it.
 *
 * Two columns do the work. "Used by" is the count of everything on the
 * platform pointing at a row, which is what stops somebody retiring the make
 * half the catalogue is filed under. And merge is the only operation here
 * that actually fixes a duplicate: retiring "Toyata" hides it from the
 * pickers but leaves its listings invisible to anybody filtering on Toyota.
 */
const props = defineProps<{
    lists: ReferenceListSummary[];
    selected: string | null;
    rows: ReferenceRow[];
    parents: { value: number; label: string }[];
    detailHref: string | null;
    can: { manage: boolean; merge: boolean };
}>();

const current = computed(
    () => props.lists.find((list) => list.key === props.selected) ?? null,
);

const editing = ref<number | null>(null);
const merging = ref(false);

const createForm = useForm({ name: '', parent_id: null as number | null });
const editForm = useForm({ name: '', is_active: true });
const mergeForm = useForm({
    source_id: null as number | null,
    target_id: null as number | null,
    reason: '',
});

const beginEdit = (row: ReferenceRow): void => {
    editing.value = row.id;
    editForm.clearErrors();
    editForm.name = row.name;
    editForm.is_active = row.is_active ?? true;
};

const create = (): void => {
    if (!props.selected) {
        return;
    }

    createForm.post(referenceData.store(props.selected).url, {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
};

const save = (row: ReferenceRow): void => {
    if (!props.selected) {
        return;
    }

    editForm.put(referenceData.update([props.selected, row.id]).url, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
};

const merge = (): void => {
    if (!props.selected) {
        return;
    }

    mergeForm.post(referenceData.merge(props.selected).url, {
        preserveScroll: true,
        onSuccess: () => {
            merging.value = false;
            mergeForm.reset();
        },
    });
};

/** The row being merged away, so the confirmation can say what it costs. */
const losing = computed(
    () => props.rows.find((row) => row.id === mergeForm.source_id) ?? null,
);

const parentName = (id: number | null): string =>
    props.parents.find((parent) => parent.value === id)?.label ?? '—';
</script>

<template>
    <Head title="Reference data" />

    <div class="space-y-6 p-4">
        <Heading
            title="Reference data"
            description="The lists everybody picks from. Keeping them clean is what stops the search facets becoming useless."
        />

        <div class="flex flex-wrap gap-2">
            <Link
                v-for="list in lists"
                :key="list.key"
                :href="referenceData.index({ query: { list: list.key } }).url"
                class="rounded-md border px-3 py-1.5 text-sm"
                :class="
                    list.key === selected
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-accent'
                "
            >
                {{ list.label }}
                <span class="tabular-nums opacity-70">({{ list.count }})</span>
            </Link>
        </div>

        <Card v-if="current">
            <CardHeader>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle>{{ current.label }}</CardTitle>
                        <CardDescription>
                            {{ current.note }}
                            <span class="opacity-70">
                                Owned by the {{ current.owner }} module.
                            </span>
                        </CardDescription>
                    </div>

                    <!-- Type-specific editing is not duplicated here. -->
                    <Button
                        v-if="detailHref"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="detailHref">
                            Full editor
                            <ExternalLink class="size-3.5" />
                        </Link>
                    </Button>
                </div>
            </CardHeader>

            <CardContent class="space-y-4">
                <form
                    v-if="can.manage"
                    class="flex flex-wrap items-end gap-2 rounded-lg border p-3"
                    @submit.prevent="create"
                >
                    <div class="min-w-48 flex-1 space-y-1.5">
                        <Label for="new-name"
                            >Add a {{ current.singular }}</Label
                        >
                        <Input id="new-name" v-model="createForm.name" />
                        <InputError :message="createForm.errors.name" />
                    </div>

                    <div v-if="current.scoped" class="min-w-48 space-y-1.5">
                        <Label for="new-parent">Belongs to</Label>
                        <select
                            id="new-parent"
                            v-model="createForm.parent_id"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option :value="null">Choose…</option>
                            <option
                                v-for="parent in parents"
                                :key="parent.value"
                                :value="parent.value"
                            >
                                {{ parent.label }}
                            </option>
                        </select>
                        <InputError :message="createForm.errors.parent_id" />
                    </div>

                    <Button type="submit" :disabled="createForm.processing">
                        <Spinner v-if="createForm.processing" />
                        <Plus v-else class="size-4" />
                        Add
                    </Button>
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-muted-foreground text-left">
                            <tr class="border-b">
                                <th class="py-2 pr-3 font-medium">Name</th>
                                <th
                                    v-if="current.scoped"
                                    class="py-2 pr-3 font-medium"
                                >
                                    Belongs to
                                </th>
                                <th class="py-2 pr-3 font-medium">Used by</th>
                                <th class="py-2 pr-3 font-medium">Status</th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in rows"
                                :key="row.id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-3">
                                    <template v-if="editing === row.id">
                                        <Input
                                            v-model="editForm.name"
                                            class="h-8"
                                        />
                                        <InputError
                                            :message="editForm.errors.name"
                                        />
                                    </template>
                                    <span v-else>{{ row.name }}</span>
                                </td>

                                <td
                                    v-if="current.scoped"
                                    class="text-muted-foreground py-2 pr-3"
                                >
                                    {{ parentName(row.parent_id) }}
                                </td>

                                <td class="py-2 pr-3 tabular-nums">
                                    {{ row.usage }}
                                </td>

                                <td class="py-2 pr-3">
                                    <label
                                        v-if="
                                            editing === row.id &&
                                            current.retirable
                                        "
                                        class="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            v-model="editForm.is_active"
                                        />
                                        <span class="text-muted-foreground">
                                            Active
                                        </span>
                                    </label>
                                    <Badge
                                        v-else-if="row.is_active === false"
                                        variant="secondary"
                                    >
                                        Retired
                                    </Badge>
                                    <span
                                        v-else-if="row.is_active === true"
                                        class="text-muted-foreground"
                                    >
                                        Active
                                    </span>
                                    <span v-else class="text-muted-foreground">
                                        —
                                    </span>
                                </td>

                                <td class="py-2 text-right">
                                    <template v-if="editing === row.id">
                                        <Button
                                            size="sm"
                                            :disabled="editForm.processing"
                                            @click="save(row)"
                                        >
                                            Save
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            @click="editing = null"
                                        >
                                            Cancel
                                        </Button>
                                    </template>
                                    <Button
                                        v-else-if="can.manage"
                                        size="sm"
                                        variant="ghost"
                                        @click="beginEdit(row)"
                                    >
                                        Edit
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card v-if="current && can.merge">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Merge class="size-4" />
                    Merge duplicates
                </CardTitle>
                <CardDescription>
                    Moves everything pointing at one {{ current.singular }} onto
                    another and deletes the first. There is no undo.
                </CardDescription>
            </CardHeader>

            <CardContent class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="merge-source">Merge this away</Label>
                        <select
                            id="merge-source"
                            v-model="mergeForm.source_id"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option :value="null">Choose…</option>
                            <option
                                v-for="row in rows"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }} ({{ row.usage }})
                            </option>
                        </select>
                        <InputError :message="mergeForm.errors.source_id" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="merge-target">Into this one</Label>
                        <select
                            id="merge-target"
                            v-model="mergeForm.target_id"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option :value="null">Choose…</option>
                            <option
                                v-for="row in rows"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }} ({{ row.usage }})
                            </option>
                        </select>
                        <InputError :message="mergeForm.errors.target_id" />
                    </div>
                </div>

                <p
                    v-if="losing && losing.usage > 0"
                    class="text-destructive text-sm"
                >
                    {{ losing.usage }} row{{
                        losing.usage === 1 ? '' : 's'
                    }}
                    will be moved off “{{ losing.name }}”, and “{{
                        losing.name
                    }}” will be deleted.
                </p>

                <div class="space-y-1.5">
                    <Label for="merge-reason">Why?</Label>
                    <Input
                        id="merge-reason"
                        v-model="mergeForm.reason"
                        placeholder="Recorded in the audit trail against your name."
                    />
                    <InputError :message="mergeForm.errors.reason" />
                </div>

                <div class="flex justify-end gap-2">
                    <Button
                        v-if="!merging"
                        variant="destructive"
                        :disabled="!mergeForm.source_id || !mergeForm.target_id"
                        @click="merging = true"
                    >
                        Merge
                    </Button>

                    <template v-else>
                        <Button variant="ghost" @click="merging = false">
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            :disabled="mergeForm.processing"
                            @click="merge"
                        >
                            <Spinner v-if="mergeForm.processing" />
                            Yes, merge and delete
                        </Button>
                    </template>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
