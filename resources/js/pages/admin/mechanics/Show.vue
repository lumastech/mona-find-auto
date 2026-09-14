<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { FileText, Lock } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import adminMechanics from '@/routes/admin/mechanics';
import type { MechanicEndorsement, MechanicStatusValue } from '@/types';

/**
 * One mechanic's application, in full.
 *
 * The only screen anywhere that shows the references and the certificates —
 * a third party's phone number and scans of somebody's papers, loaded here
 * and nowhere else.
 *
 * Note what is missing: there is no edit form. Staff approve a mechanic's
 * claims; they do not write them.
 */
const props = defineProps<{
    mechanic: {
        id: number;
        slug: string;
        display_name: string;
        headline: string | null;
        bio: string | null;
        qualification: string;
        qualification_institution: string | null;
        qualification_year: number | null;
        years_experience: number;
        locality: string;
        street: string | null;
        plot_number: string | null;
        phone: string;
        email: string | null;
        is_mobile: boolean;
        accepting_work: boolean;
        avatar_url: string | null;
        specialities: string[];
        work_history: {
            employer: string;
            role: string;
            description: string | null;
            period: string;
        }[];
        references: {
            name: string;
            relationship: string | null;
            phone: string;
            email: string | null;
            note: string | null;
        }[];
        certificates: { id: number; name: string; size: number }[];
        account: {
            id: number;
            name: string;
            email: string;
            phone: string | null;
        };
        status: MechanicStatusValue;
        status_label: string;
        status_variant: string;
        allowed_transitions: { value: string; label: string }[];
        review_note: string | null;
        rejection_reason: string | null;
        submitted_at: string | null;
        approved_at: string | null;
        approved_by: string | null;
        public_url: string | null;
    };
    endorsements: MechanicEndorsement[];
}>();

/**
 * Which refusal form is open. All three ask for a reason the applicant
 * reads, so they share one form and differ only in where it posts.
 */
const showRefusal = ref<'reject' | 'suspend' | 'reinstate' | null>(null);

const refusalAction = computed(() => {
    const slug = props.mechanic.slug;

    switch (showRefusal.value) {
        case 'reject':
            return adminMechanics.reject.form(slug);
        case 'suspend':
            return adminMechanics.suspend.form(slug);
        default:
            return adminMechanics.reinstate.form(slug);
    }
});

const can = (status: string) =>
    props.mechanic.allowed_transitions.some((t) => t.value === status);
</script>

<template>
    <Head :title="mechanic.display_name" />

    <div class="space-y-6">
        <Heading
            :title="mechanic.display_name"
            :description="mechanic.headline ?? mechanic.qualification"
        />

        <div class="flex flex-wrap items-center gap-3">
            <Badge :variant="mechanic.status_variant as never">
                {{ mechanic.status_label }}
            </Badge>
            <Link
                v-if="mechanic.public_url"
                :href="mechanic.public_url"
                class="text-sm hover:underline"
            >
                View the public profile
            </Link>
        </div>

        <Card>
            <CardHeader><CardTitle>Decision</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <Form
                    v-bind="adminMechanics.review.form(mechanic.slug)"
                    v-slot="{ errors, processing }"
                    class="space-y-3"
                >
                    <div class="space-y-1.5">
                        <Label for="note">Reviewer note (staff only)</Label>
                        <textarea
                            id="note"
                            name="note"
                            rows="2"
                            class="border-input bg-background w-full rounded-md border p-2.5 text-sm"
                        />
                        <InputError :message="errors.note" />
                        <InputError :message="errors.status" />
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="can('under_review')"
                            type="submit"
                            variant="outline"
                            :disabled="processing"
                        >
                            <Spinner v-if="processing" class="size-4" />
                            Open for review
                        </Button>
                        <Button
                            v-if="
                                can('approved') &&
                                mechanic.status !== 'suspended'
                            "
                            type="submit"
                            :disabled="processing"
                            :formaction="
                                adminMechanics.approve.url(mechanic.slug)
                            "
                        >
                            Approve
                        </Button>
                    </div>
                </Form>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="can('rejected')"
                        variant="destructive"
                        @click="showRefusal = 'reject'"
                    >
                        Reject
                    </Button>
                    <Button
                        v-if="can('suspended')"
                        variant="destructive"
                        @click="showRefusal = 'suspend'"
                    >
                        Suspend
                    </Button>
                    <Button
                        v-if="mechanic.status === 'suspended'"
                        @click="showRefusal = 'reinstate'"
                    >
                        Reinstate
                    </Button>
                </div>

                <Form
                    v-if="showRefusal"
                    v-bind="refusalAction"
                    v-slot="{ errors, processing }"
                    class="space-y-2 border-t pt-3"
                    @success="showRefusal = null"
                >
                    <Label for="reason">
                        Reason
                        <span class="text-muted-foreground font-normal">
                            — the mechanic reads this
                        </span>
                    </Label>
                    <textarea
                        id="reason"
                        name="reason"
                        rows="3"
                        required
                        class="border-input bg-background w-full rounded-md border p-2.5 text-sm"
                    />
                    <InputError :message="errors.reason" />
                    <InputError :message="errors.status" />
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" class="size-4" />
                        Confirm
                    </Button>
                </Form>

                <dl class="text-muted-foreground grid gap-1 pt-2 text-xs">
                    <div v-if="mechanic.submitted_at">
                        Submitted {{ mechanic.submitted_at }}
                    </div>
                    <div v-if="mechanic.approved_by">
                        Approved by {{ mechanic.approved_by }}
                    </div>
                    <div v-if="mechanic.review_note">
                        Last note: {{ mechanic.review_note }}
                    </div>
                    <div v-if="mechanic.rejection_reason">
                        Rejection reason: {{ mechanic.rejection_reason }}
                    </div>
                </dl>
            </CardContent>
        </Card>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader><CardTitle>The claim</CardTitle></CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <p>
                        <span class="font-medium">Qualification: </span>
                        {{ mechanic.qualification }}
                    </p>
                    <p v-if="mechanic.qualification_institution">
                        <span class="font-medium">Trained at: </span>
                        {{ mechanic.qualification_institution }}
                        <template v-if="mechanic.qualification_year">
                            ({{ mechanic.qualification_year }})
                        </template>
                    </p>
                    <p>
                        <span class="font-medium">Experience: </span>
                        {{ mechanic.years_experience }} years
                    </p>
                    <p>
                        <span class="font-medium">Specialities: </span>
                        {{ mechanic.specialities.join(', ') || '—' }}
                    </p>
                    <p v-if="mechanic.bio" class="whitespace-pre-line">
                        {{ mechanic.bio }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    ><CardTitle>Contact and account</CardTitle></CardHeader
                >
                <CardContent class="space-y-2 text-sm">
                    <p>
                        <span class="font-medium">Phone: </span>
                        {{ mechanic.phone }}
                    </p>
                    <p v-if="mechanic.email">
                        <span class="font-medium">Email: </span>
                        {{ mechanic.email }}
                    </p>
                    <p>
                        <span class="font-medium">Where: </span>
                        {{ mechanic.locality }}
                    </p>
                    <p class="text-muted-foreground pt-2">
                        Account: {{ mechanic.account.name }} ({{
                            mechanic.account.email
                        }})
                    </p>
                </CardContent>
            </Card>

            <Card v-if="mechanic.work_history.length">
                <CardHeader><CardTitle>Work history</CardTitle></CardHeader>
                <CardContent>
                    <ol class="space-y-3 text-sm">
                        <li
                            v-for="(job, index) in mechanic.work_history"
                            :key="index"
                        >
                            <p class="font-medium">
                                {{ job.role }} — {{ job.employer }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ job.period }}
                            </p>
                        </li>
                    </ol>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Lock class="size-4" aria-hidden="true" />
                        References
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <p class="text-muted-foreground text-xs">
                        Given to MonaFind for checking only. Never shown on the
                        public profile.
                    </p>
                    <p v-if="!mechanic.references.length">None given.</p>
                    <div
                        v-for="(reference, index) in mechanic.references"
                        :key="index"
                        class="rounded-md border p-3"
                    >
                        <p class="font-medium">{{ reference.name }}</p>
                        <p class="text-muted-foreground">
                            {{ reference.relationship ?? 'Reference' }} ·
                            {{ reference.phone }}
                        </p>
                        <p v-if="reference.note" class="mt-1">
                            {{ reference.note }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="mechanic.certificates.length">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <FileText class="size-4" aria-hidden="true" />
                        Certificates
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="certificate in mechanic.certificates"
                            :key="certificate.id"
                        >
                            {{ certificate.name }}
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Endorsements</CardTitle></CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <p
                        v-if="!endorsements.length"
                        class="text-muted-foreground"
                    >
                        No shop has been asked yet.
                    </p>
                    <div
                        v-for="endorsement in endorsements"
                        :key="endorsement.id"
                        class="flex items-center justify-between gap-3"
                    >
                        <span>{{ endorsement.seller.business_name }}</span>
                        <Badge :variant="endorsement.status_variant as never">
                            {{ endorsement.status_label }}
                        </Badge>
                    </div>
                    <p class="text-muted-foreground pt-2 text-xs">
                        Endorsements are a shop's own word. MonaFind approves
                        mechanics; it does not endorse them.
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
