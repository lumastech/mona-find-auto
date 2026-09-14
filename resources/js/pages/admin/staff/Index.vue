<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Copy, ShieldAlert, UserPlus } from '@lucide/vue';
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
import adminStaff from '@/routes/admin/staff';
import type { StaffInvitationSummary, StaffMember } from '@/types';

/**
 * Who works for MonaFind and what they may touch.
 *
 * Roles are checkboxes rather than a single choice because they compose: the
 * person who runs finance is often also a platform administrator, and forcing
 * a choice between them would mean one of the two jobs gets done from
 * somebody else's account.
 *
 * Every change here carries a reason, including taking somebody off the
 * console. Removing a colleague's access is exactly the kind of action that
 * needs a sentence attached to it a year later.
 */
const props = defineProps<{
    staff: {
        data: StaffMember[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { search: string | null; role: string | null };
    roles: { value: string; label: string }[];
    invitations: StaffInvitationSummary[];
    twoFactorOutstanding: number;
}>();

const page = usePage();

/** Shown once, straight after inviting: the queue can be minutes behind. */
const invitationLink = computed(
    () => (page.props.invitationLink as string | undefined) ?? null,
);

const inviting = ref(false);
const editing = ref<number | null>(null);

const inviteForm = useForm({
    email: '',
    name: '',
    role: 'moderator',
    reason: '',
});

const rolesForm = useForm({ roles: [] as string[], reason: '' });
const statusForm = useForm({ reason: '' });

const beginEdit = (member: StaffMember): void => {
    editing.value = member.id;
    rolesForm.clearErrors();
    rolesForm.roles = props.roles
        .map((role) => role.value)
        .filter((role) => member.roles.includes(role));
    rolesForm.reason = '';
};

const invite = (): void => {
    inviteForm.post(adminStaff.invite().url, {
        preserveScroll: true,
        onSuccess: () => {
            inviting.value = false;
            inviteForm.reset();
        },
    });
};

const saveRoles = (member: StaffMember): void => {
    rolesForm.put(adminStaff.roles(member.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
};

const setStatus = (member: StaffMember, active: boolean): void => {
    const url = active
        ? adminStaff.reinstate(member.id).url
        : adminStaff.deactivate(member.id).url;

    statusForm.post(url, {
        preserveScroll: true,
        onSuccess: () => statusForm.reset(),
    });
};

const revoke = (invitation: StaffInvitationSummary): void => {
    useForm({ reason: 'Invitation no longer needed.' }).post(
        adminStaff.invitations.revoke(invitation.id).url,
        { preserveScroll: true },
    );
};

const copy = (value: string): void => {
    void navigator.clipboard?.writeText(value);
};
</script>

<template>
    <Head title="Staff" />

    <div class="space-y-6 p-4">
        <Heading
            title="Staff"
            description="Who works here, what they may touch, and who is still to enrol in two-factor authentication."
        />

        <div
            v-if="twoFactorOutstanding > 0"
            class="border-destructive/40 flex items-start gap-2 rounded-lg border p-3 text-sm"
        >
            <ShieldAlert class="text-destructive mt-0.5 size-4 shrink-0" />
            <p>
                {{ twoFactorOutstanding }} staff
                {{
                    twoFactorOutstanding === 1 ? 'account has' : 'accounts have'
                }}
                not set up two-factor authentication. They are held at the
                enrolment screen and can reach nothing until they finish.
            </p>
        </div>

        <div
            v-if="invitationLink"
            class="flex flex-wrap items-center gap-2 rounded-lg border p-3 text-sm"
        >
            <span class="text-muted-foreground">
                Invitation link (shown once):
            </span>
            <code class="min-w-0 flex-1 truncate text-xs">
                {{ invitationLink }}
            </code>
            <Button size="sm" variant="outline" @click="copy(invitationLink)">
                <Copy class="size-3.5" />
                Copy
            </Button>
        </div>

        <Card>
            <CardHeader>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle>Console accounts</CardTitle>
                        <CardDescription>
                            Roles compose. Somebody may hold more than one.
                        </CardDescription>
                    </div>

                    <Button size="sm" @click="inviting = !inviting">
                        <UserPlus class="size-4" />
                        Invite
                    </Button>
                </div>
            </CardHeader>

            <CardContent class="space-y-4">
                <form
                    v-if="inviting"
                    class="grid gap-3 rounded-lg border p-3 sm:grid-cols-2"
                    @submit.prevent="invite"
                >
                    <div class="space-y-1.5">
                        <Label for="invite-name">Name</Label>
                        <Input id="invite-name" v-model="inviteForm.name" />
                        <InputError :message="inviteForm.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="invite-email">Email</Label>
                        <Input
                            id="invite-email"
                            v-model="inviteForm.email"
                            type="email"
                        />
                        <InputError :message="inviteForm.errors.email" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="invite-role">Role</Label>
                        <select
                            id="invite-role"
                            v-model="inviteForm.role"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option
                                v-for="role in roles"
                                :key="role.value"
                                :value="role.value"
                            >
                                {{ role.label }}
                            </option>
                        </select>
                        <InputError :message="inviteForm.errors.role" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="invite-reason">Why?</Label>
                        <Input id="invite-reason" v-model="inviteForm.reason" />
                        <InputError :message="inviteForm.errors.reason" />
                    </div>

                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <Button variant="ghost" @click="inviting = false">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="inviteForm.processing">
                            <Spinner v-if="inviteForm.processing" />
                            Send invitation
                        </Button>
                    </div>
                </form>

                <div
                    v-for="member in staff.data"
                    :key="member.id"
                    class="rounded-lg border p-3"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                <Link
                                    :href="member.href"
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ member.name }}
                                </Link>
                                <span
                                    v-if="member.is_self"
                                    class="text-muted-foreground text-xs"
                                >
                                    (you)
                                </span>
                            </p>
                            <p class="text-muted-foreground text-sm">
                                {{ member.email }}
                            </p>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <Badge
                                    v-for="role in member.roles"
                                    :key="role"
                                    variant="secondary"
                                >
                                    {{ role }}
                                </Badge>
                                <Badge
                                    v-if="!member.two_factor_ready"
                                    variant="destructive"
                                >
                                    2FA not set up
                                </Badge>
                                <Badge
                                    v-if="member.status !== 'active'"
                                    variant="destructive"
                                >
                                    {{ member.status_label }}
                                </Badge>
                            </div>
                        </div>

                        <div class="flex gap-2">
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="
                                    editing === member.id
                                        ? (editing = null)
                                        : beginEdit(member)
                                "
                            >
                                Roles
                            </Button>
                            <Button
                                v-if="!member.is_self"
                                size="sm"
                                :variant="
                                    member.status === 'active'
                                        ? 'outline'
                                        : 'default'
                                "
                                @click="
                                    setStatus(
                                        member,
                                        member.status !== 'active',
                                    )
                                "
                                :disabled="
                                    statusForm.processing ||
                                    statusForm.reason.length < 5
                                "
                            >
                                {{
                                    member.status === 'active'
                                        ? 'Deactivate'
                                        : 'Reinstate'
                                }}
                            </Button>
                        </div>
                    </div>

                    <div
                        v-if="editing === member.id"
                        class="mt-3 space-y-3 border-t pt-3"
                    >
                        <div class="flex flex-wrap gap-4">
                            <label
                                v-for="role in roles"
                                :key="role.value"
                                class="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    :model-value="
                                        rolesForm.roles.includes(role.value)
                                    "
                                    @update:model-value="
                                        rolesForm.roles = $event
                                            ? [...rolesForm.roles, role.value]
                                            : rolesForm.roles.filter(
                                                  (held) => held !== role.value,
                                              )
                                    "
                                />
                                {{ role.label }}
                            </label>
                        </div>

                        <div class="space-y-1.5">
                            <Label :for="`reason-${member.id}`">Why?</Label>
                            <Input
                                :id="`reason-${member.id}`"
                                v-model="rolesForm.reason"
                            />
                            <InputError :message="rolesForm.errors.reason" />
                        </div>

                        <div class="flex justify-end">
                            <Button
                                size="sm"
                                :disabled="rolesForm.processing"
                                @click="saveRoles(member)"
                            >
                                <Spinner v-if="rolesForm.processing" />
                                Save roles
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="space-y-1.5 border-t pt-4">
                    <Label for="status-reason">
                        Reason for deactivating or reinstating
                    </Label>
                    <Input
                        id="status-reason"
                        v-model="statusForm.reason"
                        placeholder="Required before either button will work."
                    />
                    <InputError :message="statusForm.errors.reason" />
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Invitations</CardTitle>
                <CardDescription>
                    An invitation is the only way into a staff role. They
                    expire.
                </CardDescription>
            </CardHeader>

            <CardContent class="space-y-2">
                <p
                    v-if="invitations.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    None sent.
                </p>

                <div
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ invitation.email }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ invitation.role_label }}
                            <template v-if="invitation.invited_by">
                                · invited by {{ invitation.invited_by }}
                            </template>
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Badge :variant="invitation.badge as never">
                            {{ invitation.status_label }}
                        </Badge>
                        <Button
                            v-if="invitation.is_open"
                            size="sm"
                            variant="ghost"
                            @click="revoke(invitation)"
                        >
                            Revoke
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
