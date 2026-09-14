<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ShieldOff } from '@lucide/vue';
import { ref } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/**
 * Account deletions: what is coming, and what is holding any of it up.
 *
 * There is no cancel button on this screen and there is not meant to be. Staff
 * may hold a request while an order or a dispute settles; only the account
 * holder can call one off. An erasure staff could refuse would not be a right.
 */
interface ErasureRow {
    id: number;
    status: string;
    status_label: string;
    requested_at: string;
    erase_after: string;
    completed_at: string | null;
    blocked_reason: string | null;
    blocked_by: string | null;
    records_erased: number;
    account: { id: number; name: string | null; email: string | null };
    blockers: string[];
}

defineProps<{
    requests: {
        data: ErasureRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { status?: string };
    statuses: Array<{ value: string; label: string }>;
}>();

const openId = ref<number | null>(null);
const holdForm = useForm({ reason: '' });

function hold(id: number): void {
    holdForm.post(`/admin/privacy/erasure-requests/${id}/block`, {
        preserveScroll: true,
        onSuccess: () => {
            openId.value = null;
            holdForm.reset();
        },
    });
}

function release(id: number): void {
    router.post(
        `/admin/privacy/erasure-requests/${id}/release`,
        {},
        { preserveScroll: true },
    );
}

function filterBy(status: string): void {
    router.get('/admin/privacy/erasure-requests', status ? { status } : {}, {
        preserveState: true,
        replace: true,
    });
}

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}

function toneFor(status: string): 'default' | 'secondary' | 'destructive' {
    if (status === 'blocked') {
        return 'destructive';
    }

    return status === 'completed' ? 'secondary' : 'default';
}
</script>

<template>
    <Head title="Account deletions" />

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Account deletions
            </h1>
            <p class="text-muted-foreground mt-1 text-sm">
                Requests under the Data Protection Act. Deleted accounts keep
                their orders and ledger entries; everything personal is removed.
            </p>
        </div>

        <Alert>
            <ShieldOff class="size-4" aria-hidden="true" />
            <AlertDescription>
                You can hold a deletion while an order or dispute settles. Only
                the account holder can cancel one.
            </AlertDescription>
        </Alert>

        <div class="flex flex-wrap gap-2">
            <Button
                size="sm"
                :variant="filters.status ? 'outline' : 'default'"
                @click="filterBy('')"
            >
                Open
            </Button>
            <Button
                v-for="status in statuses"
                :key="status.value"
                size="sm"
                :variant="
                    filters.status === status.value ? 'default' : 'outline'
                "
                @click="filterBy(status.value)"
            >
                {{ status.label }}
            </Button>
        </div>

        <Card>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Account</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 font-medium">Asked</th>
                            <th class="px-4 py-2 font-medium">Due</th>
                            <th class="px-4 py-2 font-medium">Holding it up</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-border divide-y">
                        <tr v-for="row in requests.data" :key="row.id">
                            <td class="px-4 py-3 align-top">
                                <p class="font-medium">
                                    {{ row.account.name ?? 'Erased account' }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{
                                        row.account.email ??
                                        `Account ${row.account.id}`
                                    }}
                                </p>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <Badge :variant="toneFor(row.status)">
                                    {{ row.status_label }}
                                </Badge>
                                <p
                                    v-if="row.status === 'completed'"
                                    class="text-muted-foreground mt-1"
                                >
                                    {{ row.records_erased }} records erased
                                </p>
                            </td>

                            <td
                                class="text-muted-foreground px-4 py-3 align-top"
                            >
                                {{ formatDate(row.requested_at) }}
                            </td>

                            <td
                                class="text-muted-foreground px-4 py-3 align-top"
                            >
                                {{
                                    row.completed_at
                                        ? formatDate(row.completed_at)
                                        : formatDate(row.erase_after)
                                }}
                            </td>

                            <td class="px-4 py-3 align-top">
                                <p
                                    v-if="row.blocked_reason"
                                    class="text-destructive"
                                >
                                    {{ row.blocked_reason }}
                                    <span
                                        v-if="row.blocked_by"
                                        class="text-muted-foreground"
                                    >
                                        — {{ row.blocked_by }}
                                    </span>
                                </p>
                                <ul
                                    v-else-if="row.blockers.length"
                                    class="text-muted-foreground list-disc pl-4"
                                >
                                    <li
                                        v-for="blocker in row.blockers"
                                        :key="blocker"
                                    >
                                        {{ blocker }}
                                    </li>
                                </ul>
                                <span v-else class="text-muted-foreground">
                                    Nothing
                                </span>
                            </td>

                            <td class="px-4 py-3 text-right align-top">
                                <div
                                    v-if="row.status === 'blocked'"
                                    class="flex justify-end"
                                >
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="release(row.id)"
                                    >
                                        Let it go ahead
                                    </Button>
                                </div>

                                <div
                                    v-else-if="row.status === 'pending'"
                                    class="space-y-2"
                                >
                                    <Button
                                        v-if="openId !== row.id"
                                        size="sm"
                                        variant="outline"
                                        @click="openId = row.id"
                                    >
                                        Hold
                                    </Button>

                                    <form
                                        v-else
                                        class="flex flex-col items-end gap-2"
                                        @submit.prevent="hold(row.id)"
                                    >
                                        <Input
                                            v-model="holdForm.reason"
                                            placeholder="Why — the account holder sees this"
                                            required
                                        />
                                        <div class="flex gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                @click="openId = null"
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                :disabled="holdForm.processing"
                                            >
                                                Hold
                                            </Button>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="!requests.data.length">
                            <td
                                colspan="6"
                                class="text-muted-foreground px-4 py-8 text-center"
                            >
                                Nothing to do.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
