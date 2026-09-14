<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge, type BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import sellerRoutes from '@/routes/admin/sellers';
import type {
    LabelledOption,
    SellerTypeOption,
    VerificationStatusValue,
} from '@/types';

type SellerRow = {
    id: number;
    slug: string;
    business_name: string;
    type_label: string;
    registration_number: string | null;
    status: VerificationStatusValue;
    status_label: string;
    verified: boolean;
    location: string;
    owner_name: string | null;
    submitted_at: string | null;
};

const props = defineProps<{
    sellers: {
        data: SellerRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: {
        search?: string;
        status?: string;
        type?: string;
        queue?: boolean;
    };
    statuses: LabelledOption[];
    types: SellerTypeOption[];
    queueCount: number;
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const type = ref(props.filters.type ?? '');
const queueOnly = ref(Boolean(props.filters.queue));

let debounce: number | undefined;

/**
 * Push the filters into the URL so a reviewer can share or bookmark a
 * particular view — "the verification queue" is a link, not a set of steps.
 */
function applyFilters(): void {
    window.clearTimeout(debounce);

    debounce = window.setTimeout(() => {
        router.get(
            sellerRoutes.index().url,
            {
                search: search.value || undefined,
                status: status.value || undefined,
                type: type.value || undefined,
                queue: queueOnly.value ? 1 : undefined,
            },
            { preserveState: true, replace: true },
        );
    }, 300);
}

watch([search, status, type, queueOnly], applyFilters);

const statusTone: Record<VerificationStatusValue, BadgeVariants['variant']> = {
    draft: 'outline',
    submitted: 'secondary',
    under_review: 'secondary',
    inspection_scheduled: 'secondary',
    verified: 'default',
    rejected: 'destructive',
    suspended: 'destructive',
};

const selectClasses =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none';

const shortDate = (value: string | null): string =>
    value === null ? '—' : new Date(value).toLocaleDateString();
</script>

<template>
    <Head title="Sellers" />

    <div class="space-y-6 p-4">
        <Heading
            title="Sellers"
            :description="`${sellers.total} business${sellers.total === 1 ? '' : 'es'} · ${queueCount} waiting on a decision`"
        />

        <div class="grid gap-4 sm:grid-cols-4">
            <div class="grid gap-2">
                <Label for="search">Search</Label>
                <Input
                    id="search"
                    v-model="search"
                    placeholder="Name, PACRA number, contact"
                />
            </div>

            <div class="grid gap-2">
                <Label for="status">Status</Label>
                <select id="status" v-model="status" :class="selectClasses">
                    <option value="">Any status</option>
                    <option
                        v-for="option in statuses"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label for="type">Business type</Label>
                <select id="type" v-model="type" :class="selectClasses">
                    <option value="">Any type</option>
                    <option
                        v-for="option in types"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="queueOnly" type="checkbox" class="size-4" />
                    Verification queue only
                </label>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-3 font-medium">Business</th>
                        <th class="p-3 font-medium">Type</th>
                        <th class="p-3 font-medium">Location</th>
                        <th class="p-3 font-medium">Status</th>
                        <th class="p-3 font-medium">Submitted</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="seller in sellers.data"
                        :key="seller.id"
                        class="hover:bg-muted/30"
                    >
                        <td class="p-3">
                            <Link
                                :href="sellerRoutes.show(seller.slug)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ seller.business_name }}
                            </Link>
                            <p class="text-muted-foreground">
                                {{
                                    seller.registration_number ??
                                    'No PACRA number'
                                }}
                                · {{ seller.owner_name }}
                            </p>
                        </td>
                        <td class="p-3">{{ seller.type_label }}</td>
                        <td class="p-3">{{ seller.location }}</td>
                        <td class="p-3">
                            <Badge :variant="statusTone[seller.status]">
                                {{ seller.status_label }}
                            </Badge>
                        </td>
                        <td class="p-3">
                            {{ shortDate(seller.submitted_at) }}
                        </td>
                    </tr>

                    <tr v-if="!sellers.data.length">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-6 text-center"
                        >
                            No sellers match those filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="sellers.links.length > 3" class="flex flex-wrap gap-1">
            <Button
                v-for="link in sellers.links"
                :key="link.label"
                as-child
                size="sm"
                :variant="link.active ? 'default' : 'outline'"
                :disabled="!link.url"
            >
                <Link v-if="link.url" :href="link.url" v-html="link.label" />
                <span v-else v-html="link.label" />
            </Button>
        </nav>
    </div>
</template>
