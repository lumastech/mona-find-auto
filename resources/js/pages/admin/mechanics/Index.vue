<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { LabelledOption, MechanicStatusValue } from '@/types';

/**
 * The mechanic approval queue.
 *
 * Defaults to what still needs a decision rather than to everybody: the
 * screen exists to clear applications, and page one of every mechanic on the
 * platform is not the thing a reviewer opened it for.
 */
type MechanicRow = {
    id: number;
    slug: string;
    display_name: string;
    account: string;
    qualification: string;
    years_experience: number;
    locality: string;
    specialities: string[];
    endorsement_count: number;
    status: MechanicStatusValue;
    status_label: string;
    status_variant: string;
    submitted_at: string | null;
};

const props = defineProps<{
    mechanics: {
        data: MechanicRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { search: string | null; status: string | null };
    statuses: LabelledOption[];
    queueCount: number;
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

let timer: ReturnType<typeof setTimeout>;

watch([search, status], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            '/admin/mechanics',
            {
                search: search.value || undefined,
                status: status.value || undefined,
            },
            { preserveState: true, replace: true },
        );
    }, 300);
});
</script>

<template>
    <Head title="Mechanics" />

    <div class="space-y-6">
        <Heading
            title="Mechanics"
            :description="`${queueCount} application${queueCount === 1 ? '' : 's'} waiting for a decision.`"
        />

        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1 space-y-1.5">
                <Label for="search">Search</Label>
                <Input
                    id="search"
                    v-model="search"
                    placeholder="Name or qualification"
                />
            </div>
            <div class="space-y-1.5">
                <Label for="status">Status</Label>
                <select
                    id="status"
                    v-model="status"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="">Waiting for a decision</option>
                    <option
                        v-for="option in statuses"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-3 font-medium">Mechanic</th>
                        <th class="p-3 font-medium">Qualification</th>
                        <th class="p-3 font-medium">Where</th>
                        <th class="p-3 font-medium">Endorsements</th>
                        <th class="p-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="mechanic in mechanics.data"
                        :key="mechanic.id"
                        class="hover:bg-muted/30 border-t"
                    >
                        <td class="p-3">
                            <Link
                                :href="`/admin/mechanics/${mechanic.slug}`"
                                class="font-medium hover:underline"
                            >
                                {{ mechanic.display_name }}
                            </Link>
                            <p class="text-muted-foreground text-xs">
                                {{ mechanic.account }}
                            </p>
                        </td>
                        <td class="p-3">
                            {{ mechanic.qualification }}
                            <p class="text-muted-foreground text-xs">
                                {{ mechanic.years_experience }} years
                            </p>
                        </td>
                        <td class="p-3">{{ mechanic.locality }}</td>
                        <td class="p-3 tabular-nums">
                            {{ mechanic.endorsement_count }}
                        </td>
                        <td class="p-3">
                            <Badge :variant="mechanic.status_variant as never">
                                {{ mechanic.status_label }}
                            </Badge>
                        </td>
                    </tr>
                    <tr v-if="!mechanics.data.length">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-8 text-center"
                        >
                            Nothing here.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="mechanics.links.length > 3"
            class="flex flex-wrap justify-center gap-1"
            aria-label="Pages"
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
