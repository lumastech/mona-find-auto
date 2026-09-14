<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge, type BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import userRoutes from '@/routes/admin/users';
import type { AccountStatus } from '@/types';

type UserRow = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: AccountStatus;
    status_label: string;
    roles: string[];
    created_at: string | null;
    last_seen_at: string | null;
};

type Option = { value: string; label: string };

const props = defineProps<{
    users: {
        data: UserRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { search?: string; role?: string; status?: string };
    statuses: Option[];
    roles: Option[];
}>();

const search = ref(props.filters.search ?? '');
const role = ref(props.filters.role ?? '');
const status = ref(props.filters.status ?? '');

let debounce: number | undefined;

/**
 * Push the filters into the URL so a staff member can share or bookmark a
 * particular view — "every suspended seller" is a link, not a set of steps.
 */
function applyFilters(): void {
    window.clearTimeout(debounce);

    debounce = window.setTimeout(() => {
        router.get(
            userRoutes.index().url,
            {
                search: search.value || undefined,
                role: role.value || undefined,
                status: status.value || undefined,
            },
            { preserveState: true, replace: true },
        );
    }, 300);
}

watch([search, role, status], applyFilters);

const statusTone: Record<AccountStatus, BadgeVariants['variant']> = {
    pending: 'secondary',
    active: 'default',
    suspended: 'destructive',
    closed: 'outline',
};

const selectClasses =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none';
</script>

<template>
    <Head title="Accounts" />

    <div class="space-y-6 p-4">
        <Heading
            title="Accounts"
            :description="`${users.total} account${users.total === 1 ? '' : 's'} on the platform`"
        />

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="search">Search</Label>
                <Input
                    id="search"
                    v-model="search"
                    type="search"
                    placeholder="Name, email or number"
                />
            </div>

            <div class="grid gap-2">
                <Label for="role">Role</Label>
                <select id="role" v-model="role" :class="selectClasses">
                    <option value="">Any role</option>
                    <option
                        v-for="option in roles"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
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
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-muted-foreground border-b">
                    <tr>
                        <th scope="col" class="py-2 pr-4 font-medium">
                            Account
                        </th>
                        <th scope="col" class="py-2 pr-4 font-medium">
                            Contact
                        </th>
                        <th scope="col" class="py-2 pr-4 font-medium">Roles</th>
                        <th scope="col" class="py-2 pr-4 font-medium">
                            Status
                        </th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-border divide-y">
                    <tr v-for="row in users.data" :key="row.id">
                        <td class="py-3 pr-4 font-medium">{{ row.name }}</td>
                        <td class="text-muted-foreground py-3 pr-4">
                            <div>{{ row.email }}</div>
                            <div>{{ row.phone ?? '—' }}</div>
                        </td>
                        <td class="py-3 pr-4">
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="name in row.roles"
                                    :key="name"
                                    variant="outline"
                                >
                                    {{ name }}
                                </Badge>
                            </div>
                        </td>
                        <td class="py-3 pr-4">
                            <Badge :variant="statusTone[row.status]">
                                {{ row.status_label }}
                            </Badge>
                        </td>
                        <td class="py-3 text-right">
                            <Button variant="ghost" size="sm" as-child>
                                <Link :href="userRoutes.show(row.id)"
                                    >View</Link
                                >
                            </Button>
                        </td>
                    </tr>

                    <tr v-if="!users.data.length">
                        <td
                            colspan="5"
                            class="text-muted-foreground py-8 text-center"
                        >
                            No accounts match those filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="users.links.length > 3" class="flex flex-wrap gap-1">
            <Button
                v-for="link in users.links"
                :key="link.label"
                :variant="link.active ? 'default' : 'outline'"
                size="sm"
                :disabled="!link.url"
                as-child
            >
                <Link v-if="link.url" :href="link.url" v-html="link.label" />
                <span v-else v-html="link.label" />
            </Button>
        </nav>
    </div>
</template>
