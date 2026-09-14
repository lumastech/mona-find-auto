<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { login, register } from '@/routes';
import type { StaffInvitationSummary } from '@/types';

/**
 * Accepting an invitation to join MonaFind staff.
 *
 * The role attaches to an ACCOUNT, so an invitee signs in or registers first.
 * There is no path that creates an account from the token: a staff account
 * whose password nobody chose is not one anybody should be moderating from.
 */
const props = defineProps<{
    token: string;
    invitation:
        | (Pick<StaffInvitationSummary, 'name' | 'email' | 'role_label'> & {
              expires_at: string;
          })
        | null;
    signedInAs: string | null;
    emailMatches: boolean;
}>();

const form = useForm({});

const accept = (): void => {
    form.post(`/staff-invitations/${props.token}`);
};
</script>

<template>
    <Head title="Staff invitation" />

    <div class="mx-auto max-w-lg px-4 py-16">
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <ShieldCheck class="text-primary size-5" />
                    Staff invitation
                </CardTitle>
                <CardDescription v-if="invitation">
                    {{ invitation.name }}, you have been invited to join
                    MonaFind as {{ invitation.role_label }}.
                </CardDescription>
                <CardDescription v-else>
                    This invitation has expired, been revoked, or was already
                    accepted.
                </CardDescription>
            </CardHeader>

            <CardContent v-if="invitation" class="space-y-4">
                <p class="text-muted-foreground text-sm">
                    It was sent to <strong>{{ invitation.email }}</strong
                    >, and the account you accept with has to use that address.
                </p>

                <p class="text-muted-foreground text-sm">
                    Staff accounts must use two-factor authentication. You will
                    be asked to set it up before the console opens.
                </p>

                <template v-if="!signedInAs">
                    <div class="flex flex-wrap gap-2">
                        <Button as-child>
                            <Link :href="login()">Sign in to accept</Link>
                        </Button>
                        <Button as-child variant="outline">
                            <Link :href="register()">Create an account</Link>
                        </Button>
                    </div>
                </template>

                <template v-else-if="!emailMatches">
                    <p class="text-destructive text-sm">
                        You are signed in as {{ signedInAs }}, which is not the
                        address this invitation was sent to. Sign out and sign
                        back in as {{ invitation.email }}.
                    </p>
                </template>

                <template v-else>
                    <Button :disabled="form.processing" @click="accept">
                        <Spinner v-if="form.processing" />
                        Accept and open the console
                    </Button>
                </template>
            </CardContent>
        </Card>
    </div>
</template>
