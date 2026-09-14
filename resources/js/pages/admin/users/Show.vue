<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge, type BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import userRoutes from '@/routes/admin/users';
import type { AccountStatus } from '@/types';

type Activity = {
    id: number;
    action: string;
    actor: string | null;
    reason: string | null;
    created_at: string;
};

const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        phone: string | null;
        status: AccountStatus;
        status_label: string;
        status_reason: string | null;
        status_changed_at: string | null;
        roles: string[];
        email_verified_at: string | null;
        phone_verified_at: string | null;
        two_factor_enabled: boolean;
        two_factor_required: boolean;
        created_at: string | null;
        last_seen_at: string | null;
        address: {
            street: string;
            plot_number: string | null;
            city: string | null;
            province: string | null;
        } | null;
        addresses: {
            id: number;
            label: string;
            single_line: string;
            is_default: boolean;
        }[];
        social_accounts: { provider: string; email: string | null }[];
    };
    activity: Activity[];
    can: { moderate: boolean; assignRoles: boolean };
}>();

/**
 * Which moderation form is open. Every action needs a typed reason, so they
 * are never one-click — the reason is what somebody reviewing the decision
 * later actually reads.
 */
const action = ref<'warn' | 'suspend' | 'reinstate' | 'close' | null>(null);

const actionRoutes = {
    warn: userRoutes.warn,
    suspend: userRoutes.suspend,
    reinstate: userRoutes.reinstate,
    close: userRoutes.close,
} as const;

const actionLabels = {
    warn: 'Record a warning',
    suspend: 'Suspend this account',
    reinstate: 'Reinstate this account',
    close: 'Close this account',
} as const;

const available = (): (keyof typeof actionRoutes)[] =>
    props.user.status === 'suspended'
        ? ['reinstate', 'close']
        : props.user.status === 'closed'
          ? ['reinstate']
          : ['warn', 'suspend', 'close'];

const statusTone: Record<AccountStatus, BadgeVariants['variant']> = {
    pending: 'secondary',
    active: 'default',
    suspended: 'destructive',
    closed: 'outline',
};

function formatTimestamp(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <Head :title="user.name" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading :title="user.name" :description="user.email" />

            <Button variant="outline" size="sm" as-child>
                <Link :href="userRoutes.index()">Back to accounts</Link>
            </Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Account</CardTitle>
                </CardHeader>

                <CardContent>
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">Status</dt>
                            <dd class="mt-1">
                                <Badge :variant="statusTone[user.status]">
                                    {{ user.status_label }}
                                </Badge>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-muted-foreground">Roles</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in user.roles"
                                    :key="role"
                                    variant="outline"
                                >
                                    {{ role }}
                                </Badge>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-muted-foreground">Mobile number</dt>
                            <dd class="mt-1">
                                {{ user.phone ?? '—' }}
                                <span
                                    v-if="user.phone && !user.phone_verified_at"
                                    class="text-muted-foreground"
                                >
                                    (unconfirmed)
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-muted-foreground">
                                Email verified
                            </dt>
                            <dd class="mt-1">
                                {{ formatTimestamp(user.email_verified_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-muted-foreground">Two-factor</dt>
                            <dd class="mt-1">
                                {{
                                    user.two_factor_enabled
                                        ? 'Enabled'
                                        : 'Not set up'
                                }}
                                <span
                                    v-if="user.two_factor_required"
                                    class="text-muted-foreground"
                                >
                                    (required for this role)
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-muted-foreground">Last seen</dt>
                            <dd class="mt-1">
                                {{ formatTimestamp(user.last_seen_at) }}
                            </dd>
                        </div>

                        <div v-if="user.address" class="sm:col-span-2">
                            <dt class="text-muted-foreground">Address</dt>
                            <dd class="mt-1">
                                {{
                                    [
                                        user.address.plot_number,
                                        user.address.street,
                                        user.address.city,
                                        user.address.province,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')
                                }}
                            </dd>
                        </div>

                        <div v-if="user.status_reason" class="sm:col-span-2">
                            <dt class="text-muted-foreground">
                                Last status reason
                            </dt>
                            <dd class="mt-1">{{ user.status_reason }}</dd>
                        </div>

                        <div
                            v-if="user.social_accounts.length"
                            class="sm:col-span-2"
                        >
                            <dt class="text-muted-foreground">Linked logins</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                <Badge
                                    v-for="account in user.social_accounts"
                                    :key="account.provider"
                                    variant="outline"
                                >
                                    {{ account.provider }}
                                </Badge>
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Actions</CardTitle>
                </CardHeader>

                <CardContent class="space-y-4">
                    <p
                        v-if="!can.moderate"
                        class="text-muted-foreground text-sm"
                    >
                        You cannot moderate this account.
                    </p>

                    <template v-else>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="name in available()"
                                :key="name"
                                :variant="
                                    name === 'suspend' || name === 'close'
                                        ? 'destructive'
                                        : 'outline'
                                "
                                size="sm"
                                @click="action = name"
                            >
                                {{ actionLabels[name] }}
                            </Button>
                        </div>

                        <Form
                            v-if="action"
                            :key="action"
                            v-bind="actionRoutes[action].form(user.id)"
                            v-slot="{ errors, processing }"
                            class="space-y-3"
                            @success="action = null"
                        >
                            <div class="grid gap-2">
                                <Label for="reason">
                                    Reason for
                                    {{ actionLabels[action].toLowerCase() }}
                                </Label>
                                <textarea
                                    id="reason"
                                    name="reason"
                                    rows="3"
                                    required
                                    class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                                    placeholder="What happened, and what the account holder needs to do."
                                />
                                <InputError :message="errors.reason" />
                            </div>

                            <div class="flex items-center gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="processing"
                                >
                                    <Spinner v-if="processing" />
                                    Confirm
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="action = null"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </Form>
                    </template>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Activity</CardTitle>
            </CardHeader>

            <CardContent>
                <ul
                    v-if="activity.length"
                    class="divide-border divide-y text-sm"
                >
                    <li v-for="entry in activity" :key="entry.id" class="py-3">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <span class="font-medium">{{ entry.action }}</span>
                            <span class="text-muted-foreground">
                                {{ formatTimestamp(entry.created_at) }}
                            </span>
                        </div>
                        <p class="text-muted-foreground">
                            {{ entry.actor ?? 'System' }}
                            <template v-if="entry.reason">
                                — {{ entry.reason }}</template
                            >
                        </p>
                    </li>
                </ul>

                <p v-else class="text-muted-foreground text-sm">
                    Nothing has been recorded against this account yet.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
