<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Check, ExternalLink, FileText, X } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import VerificationBadge from '@/components/storefront/VerificationBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import adminSellers from '@/routes/admin/sellers';
import sellersRoutes from '@/routes/sellers';
import type {
    LabelledOption,
    PayoutAccount,
    PolicyTypeOption,
    SellerDocument,
    SellerPolicy,
    SellerVerificationEvent,
} from '@/types';

type ChecklistItem = {
    key: string;
    label: string;
    satisfied: boolean;
    automatic: boolean;
};

const props = defineProps<{
    seller: {
        id: number;
        slug: string;
        business_name: string;
        type_label: string;
        registration_number: string | null;
        status_label: string;
        verified: boolean;
        location: string;
        phone: string;
        email: string;
        contact_person: string;
        description: string | null;
        bay_count: number | null;
        payment_mode_label: string;
        verification_note: string | null;
        rejection_reason: string | null;
        inspection_scheduled_for: string | null;
        owner: { id: number; name: string; email: string };
    };
    documents: SellerDocument[];
    policies: SellerPolicy[];
    policyTypes: PolicyTypeOption[];
    payoutAccounts: PayoutAccount[];
    history: SellerVerificationEvent[];
    activity: {
        id: number;
        action: string;
        actor: string;
        created_at: string;
    }[];
    checklist: ChecklistItem[];
    transitions: LabelledOption[];
    blockedFromVerifying: boolean;
    can: Record<
        'verify' | 'suspend' | 'viewDocuments' | 'setCommercialTerms',
        boolean
    >;
}>();

/** Which decision form is open. Only one at a time — these are not undoable. */
const open = ref<'verify' | 'reject' | 'inspection' | 'suspend' | null>(null);

const allows = (status: string): boolean =>
    props.transitions.some((transition) => transition.value === status);

const dateTime = (value: string | null): string =>
    value === null ? '—' : new Date(value).toLocaleString();
</script>

<template>
    <Head :title="seller.business_name" />

    <div class="space-y-6 p-4">
        <Heading :title="seller.business_name" :description="seller.location" />

        <div class="flex flex-wrap items-center gap-2">
            <VerificationBadge :verified="seller.verified" />
            <Badge variant="secondary">{{ seller.status_label }}</Badge>
            <Badge variant="outline">{{ seller.type_label }}</Badge>
            <Badge variant="outline">
                {{ seller.payment_mode_label }} payments
            </Badge>

            <Button as-child variant="ghost" size="sm">
                <Link :href="sellersRoutes.show(seller.slug)" target="_blank">
                    <ExternalLink class="size-4" aria-hidden="true" />
                    Public page
                </Link>
            </Button>
        </div>

        <Alert v-if="blockedFromVerifying" variant="destructive">
            <AlertTitle>No registration number on file</AlertTitle>
            <AlertDescription>
                The Verified badge tells buyers MonaFind checked a registered
                business. Ask the seller for their PACRA number before badging
                them.
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Application</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl class="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-muted-foreground">
                                    Registration number
                                </dt>
                                <dd>{{ seller.registration_number ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Owner</dt>
                                <dd>
                                    {{ seller.owner.name }} ({{
                                        seller.owner.email
                                    }})
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Phone</dt>
                                <dd>{{ seller.phone }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Email</dt>
                                <dd>{{ seller.email }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Contact person
                                </dt>
                                <dd>{{ seller.contact_person }}</dd>
                            </div>
                            <div v-if="seller.bay_count">
                                <dt class="text-muted-foreground">
                                    Service bays
                                </dt>
                                <dd>{{ seller.bay_count }}</dd>
                            </div>
                            <div
                                v-if="seller.description"
                                class="sm:col-span-2"
                            >
                                <dt class="text-muted-foreground">About</dt>
                                <dd>{{ seller.description }}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Documents</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <div
                            v-for="document in documents"
                            :key="document.value"
                            class="flex flex-wrap items-center gap-3 rounded-lg border p-3 text-sm"
                        >
                            <FileText
                                class="text-muted-foreground size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1">
                                {{ document.label }}
                                <Badge
                                    v-if="document.required"
                                    variant="secondary"
                                    class="ml-2"
                                >
                                    Required
                                </Badge>
                            </span>

                            <a
                                v-if="
                                    document.uploaded &&
                                    document.media_id &&
                                    can.viewDocuments
                                "
                                :href="
                                    adminSellers.documents.show({
                                        seller: seller.slug,
                                        media: document.media_id,
                                    }).url
                                "
                                target="_blank"
                                rel="noopener"
                                class="underline-offset-4 hover:underline"
                            >
                                Open
                            </a>
                            <span v-else class="text-muted-foreground">
                                Not uploaded
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Policies</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div
                            v-for="policy in policies.filter(
                                (p) => p.is_current,
                            )"
                            :key="policy.id"
                            class="rounded-lg border p-3"
                        >
                            <p class="font-medium">
                                {{ policy.type_label }}
                                <span class="text-muted-foreground font-normal">
                                    · version {{ policy.version }}
                                </span>
                            </p>
                            <p class="text-muted-foreground mt-1">
                                {{ policy.excerpt }}
                            </p>
                        </div>
                        <p
                            v-if="!policies.length"
                            class="text-muted-foreground"
                        >
                            Nothing published yet.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">
                            Workflow history
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol v-if="history.length" class="space-y-4 text-sm">
                            <li
                                v-for="event in history"
                                :key="event.id"
                                class="border-border border-l-2 pl-4"
                            >
                                <p class="font-medium">{{ event.summary }}</p>
                                <p
                                    v-if="event.note"
                                    class="text-muted-foreground mt-1"
                                >
                                    {{ event.note }}
                                </p>
                                <p
                                    v-if="event.reason"
                                    class="text-muted-foreground mt-1"
                                >
                                    Reason: {{ event.reason }}
                                </p>
                                <p class="text-muted-foreground mt-1 text-xs">
                                    {{ event.actor }} ·
                                    {{ dateTime(event.created_at) }}
                                </p>
                            </li>
                        </ol>
                        <p v-else class="text-muted-foreground text-sm">
                            Nothing yet.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Checklist</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="space-y-2 text-sm">
                            <li
                                v-for="item in checklist"
                                :key="item.key"
                                class="flex items-start gap-2"
                            >
                                <component
                                    :is="item.satisfied ? Check : X"
                                    class="mt-0.5 size-4 shrink-0"
                                    :class="
                                        item.satisfied
                                            ? 'text-primary'
                                            : 'text-muted-foreground'
                                    "
                                    aria-hidden="true"
                                />
                                <span>{{ item.label }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card v-if="can.verify">
                    <CardHeader>
                        <CardTitle class="text-base">Decision</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <Form
                            v-if="allows('under_review')"
                            v-bind="adminSellers.review.form(seller.slug)"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                class="w-full"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" class="size-4" />
                                Open for review
                            </Button>
                        </Form>

                        <Button
                            v-if="allows('inspection_scheduled')"
                            variant="outline"
                            size="sm"
                            class="w-full"
                            @click="
                                open =
                                    open === 'inspection' ? null : 'inspection'
                            "
                        >
                            Schedule an inspection
                        </Button>

                        <Form
                            v-if="open === 'inspection'"
                            v-bind="adminSellers.inspection.form(seller.slug)"
                            v-slot="{ errors, processing }"
                            class="space-y-2"
                            @success="open = null"
                        >
                            <Label for="inspection_scheduled_for">
                                Visit date
                            </Label>
                            <Input
                                id="inspection_scheduled_for"
                                name="inspection_scheduled_for"
                                type="datetime-local"
                                required
                            />
                            <InputError
                                :message="errors.inspection_scheduled_for"
                            />
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" class="size-4" />
                                Book it
                            </Button>
                        </Form>

                        <Button
                            v-if="allows('verified')"
                            size="sm"
                            class="w-full"
                            :disabled="blockedFromVerifying"
                            @click="open = open === 'verify' ? null : 'verify'"
                        >
                            Grant the Verified badge
                        </Button>

                        <Form
                            v-if="open === 'verify'"
                            v-bind="adminSellers.verify.form(seller.slug)"
                            v-slot="{ errors, processing }"
                            class="space-y-3"
                            @success="open = null"
                        >
                            <fieldset class="space-y-2">
                                <legend class="text-sm font-medium">
                                    Confirm what you checked
                                </legend>
                                <label
                                    v-for="item in checklist"
                                    :key="item.key"
                                    class="flex items-start gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        :name="`checklist[${item.key}]`"
                                        value="1"
                                        :checked="
                                            item.automatic && item.satisfied
                                        "
                                        class="mt-0.5 size-4"
                                    />
                                    {{ item.label }}
                                </label>
                            </fieldset>

                            <Label for="verify-note">Notes</Label>
                            <textarea
                                id="verify-note"
                                name="note"
                                rows="3"
                                class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            />
                            <InputError :message="errors.note" />
                            <InputError :message="errors.verification" />

                            <Button
                                type="submit"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" class="size-4" />
                                Verify
                            </Button>
                        </Form>

                        <Button
                            v-if="allows('rejected')"
                            variant="outline"
                            size="sm"
                            class="w-full"
                            @click="open = open === 'reject' ? null : 'reject'"
                        >
                            Reject the application
                        </Button>

                        <Form
                            v-if="open === 'reject'"
                            v-bind="adminSellers.reject.form(seller.slug)"
                            v-slot="{ errors, processing }"
                            class="space-y-2"
                            @success="open = null"
                        >
                            <Label for="reject-reason">
                                Why, and what would change your mind
                            </Label>
                            <textarea
                                id="reject-reason"
                                name="reason"
                                rows="3"
                                required
                                class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            />
                            <InputError :message="errors.reason" />

                            <Button
                                type="submit"
                                variant="destructive"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" class="size-4" />
                                Reject
                            </Button>
                        </Form>

                        <Button
                            v-if="allows('suspended') && can.suspend"
                            variant="outline"
                            size="sm"
                            class="w-full"
                            @click="
                                open = open === 'suspend' ? null : 'suspend'
                            "
                        >
                            Suspend this seller
                        </Button>

                        <Form
                            v-if="open === 'suspend'"
                            v-bind="adminSellers.suspend.form(seller.slug)"
                            v-slot="{ errors, processing }"
                            class="space-y-2"
                            @success="open = null"
                        >
                            <Label for="suspend-reason">Reason</Label>
                            <textarea
                                id="suspend-reason"
                                name="reason"
                                rows="3"
                                required
                                class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            />
                            <InputError :message="errors.reason" />

                            <Button
                                type="submit"
                                variant="destructive"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" class="size-4" />
                                Suspend
                            </Button>
                        </Form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Payout accounts</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-2 text-sm">
                        <div
                            v-for="account in payoutAccounts"
                            :key="account.id"
                            class="rounded-lg border p-3"
                        >
                            <p class="font-medium">
                                {{ account.display_name }}
                                <Badge
                                    v-if="account.is_default"
                                    variant="secondary"
                                    class="ml-2"
                                >
                                    Default
                                </Badge>
                            </p>
                            <p class="text-muted-foreground">
                                Confirmed as {{ account.resolved_name }}
                            </p>
                        </div>
                        <p
                            v-if="!payoutAccounts.length"
                            class="text-muted-foreground"
                        >
                            None on file.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Audit trail</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="space-y-2 text-sm">
                            <li
                                v-for="entry in activity.slice(0, 20)"
                                :key="entry.id"
                                class="text-muted-foreground"
                            >
                                <span class="text-foreground">
                                    {{ entry.action }}
                                </span>
                                — {{ entry.actor }},
                                {{ dateTime(entry.created_at) }}
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
