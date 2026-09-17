<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import adminAudit from '@/routes/admin/audit';
import type { AuditEntry } from '@/types';

/**
 * Who did what, to what, and why.
 *
 * Read-only by construction — the table refuses updates at both the model and
 * the database level — so there is nothing here but filters and a download.
 *
 * `before` and `after` are collapsed behind a click. Every action has a
 * different shape, and rendering all of them expanded turns a page of fifty
 * entries into something nobody scrolls to the bottom of.
 */
const props = defineProps<{
    entries: {
        data: AuditEntry[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: Record<string, string | number | null>;
    actions: string[];
    subjectTypes: { value: string; label: string }[];
    canExport: boolean;
}>();

const form = ref({
    action: props.filters.action ?? '',
    subject_type: props.filters.subject_type ?? '',
    search: props.filters.search ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const expanded = ref<number | null>(null);

const query = computed(() =>
    Object.fromEntries(
        Object.entries(form.value).filter(([, value]) => value !== ''),
    ),
);

const apply = (): void => {
    router.get(adminAudit.index().url, query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const exportHref = computed(
    () => adminAudit.export({ query: query.value }).url,
);

const when = (value: string): string => new Date(value).toLocaleString();

const changed = (entry: AuditEntry): boolean =>
    entry.before !== null || entry.after !== null;
</script>

<template>
    <Head title="Audit trail" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Audit trail"
                description="Every staff action and every money movement. Nothing here can be edited or deleted."
            />

            <!--
                A plain link, not a form: the export is a file download, and
                Inertia would try to render the CSV as a page.
            -->
            <Button v-if="canExport" as-child variant="outline" size="sm">
                <a :href="exportHref">
                    <Download class="size-3.5" />
                    Export CSV
                </a>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Filters</CardTitle>
                <CardDescription>
                    Dates are read in Lusaka time, whole days included.
                </CardDescription>
            </CardHeader>

            <CardContent class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="space-y-1.5">
                    <Label for="f-action">Action</Label>
                    <select
                        id="f-action"
                        v-model="form.action"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        @change="apply"
                    >
                        <option value="">Any</option>
                        <option
                            v-for="action in actions"
                            :key="action"
                            :value="action"
                        >
                            {{ action }}
                        </option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <Label for="f-subject">Subject</Label>
                    <select
                        id="f-subject"
                        v-model="form.subject_type"
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        @change="apply"
                    >
                        <option value="">Any</option>
                        <option
                            v-for="type in subjectTypes"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <Label for="f-from">From</Label>
                    <Input
                        id="f-from"
                        v-model="form.from"
                        type="date"
                        @change="apply"
                    />
                </div>

                <div class="space-y-1.5">
                    <Label for="f-to">To</Label>
                    <Input
                        id="f-to"
                        v-model="form.to"
                        type="date"
                        @change="apply"
                    />
                </div>

                <div class="space-y-1.5">
                    <Label for="f-search">Actor or reason</Label>
                    <Input
                        id="f-search"
                        v-model="form.search"
                        placeholder="Name or words"
                        @keyup.enter="apply"
                    />
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="p-0">
                <p
                    v-if="entries.data.length === 0"
                    class="text-muted-foreground p-4 text-sm"
                >
                    Nothing matches those filters.
                </p>

                <div
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="border-b p-3 text-sm last:border-0"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge variant="secondary">
                                    {{ entry.action }}
                                </Badge>
                                <span
                                    v-if="entry.subject_label"
                                    class="text-muted-foreground text-xs"
                                >
                                    {{ entry.subject_label }}
                                </span>
                            </div>
                            <p class="mt-1">
                                <span class="font-medium">
                                    {{ entry.actor ?? 'System' }}
                                </span>
                                <span
                                    v-if="entry.reason"
                                    class="text-muted-foreground"
                                >
                                    — {{ entry.reason }}
                                </span>
                            </p>
                        </div>

                        <div class="text-muted-foreground text-right text-xs">
                            <p>{{ when(entry.created_at) }}</p>
                            <p v-if="entry.ip">{{ entry.ip }}</p>
                        </div>
                    </div>

                    <button
                        v-if="changed(entry)"
                        type="button"
                        class="text-muted-foreground mt-1 text-xs underline underline-offset-4"
                        @click="
                            expanded = expanded === entry.id ? null : entry.id
                        "
                    >
                        {{ expanded === entry.id ? 'Hide' : 'Show' }} what
                        changed
                    </button>

                    <div
                        v-if="expanded === entry.id"
                        class="mt-2 grid gap-2 sm:grid-cols-2"
                    >
                        <pre
                            class="bg-red-100  overflow-x-auto rounded-md p-2 text-xs text-red-700 dark:text-red-400"
                            ><b>Before:</b> <br />{{ JSON.stringify(entry.before, null, 2) }}</pre>
                        <pre
                            class="bg-green-100 overflow-x-auto rounded-md p-2 text-xs text-green-700 dark:text-green-400"
                            ><b>After:</b> <br />{{ JSON.stringify(entry.after, null, 2) }}</pre>
                    </div>
                </div>
            </CardContent>
        </Card>

        <nav v-if="entries.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="link in entries.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="rounded-md border px-3 py-1 text-sm"
                :class="
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : link.url
                          ? 'hover:bg-accent'
                          : 'pointer-events-none opacity-50'
                "
                v-html="link.label"
            />
        </nav>
    </div>
</template>
