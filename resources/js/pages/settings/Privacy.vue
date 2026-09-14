<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Download, ShieldAlert, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import privacy from '@/routes/privacy';

/**
 * "Your data" — the four rights the Data Protection Act 2021 grants, on one
 * screen.
 *
 * Gathered here rather than scattered through settings because a right
 * nobody can find is not much of a right.
 *
 * Two things this screen is careful about.
 *
 * The required agreements are shown, but as a record rather than as a switch.
 * A checkbox that silently refuses to be unticked would be a lie; the way to
 * withdraw from the terms is to delete the account, and the form says so and
 * links to it.
 *
 * And anything holding a deletion up is shown BEFORE the button, not after.
 * Somebody with an order in flight should find that out here, not in an email
 * a fortnight later saying their deletion is on hold.
 */
interface Consent {
    type: string;
    label: string;
    granted: boolean;
    withdrawable: boolean;
    recorded_at: string | null;
    document_version: number | null;
}

interface Erasure {
    id: number;
    status: string;
    status_label: string;
    erase_after: string;
    blocked_reason: string | null;
    cancellable: boolean;
}

const props = defineProps<{
    consents: Consent[];
    erasure: Erasure | null;
    blockers: string[];
    graceDays: number;
    retentionUrl: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Your data', href: privacy.index() }],
    },
});

const required = computed(() =>
    props.consents.filter((consent) => !consent.withdrawable),
);
const optional = computed(() =>
    props.consents.filter((consent) => consent.withdrawable),
);

const consentForm = useForm({ type: '', granted: false });

function toggle(consent: Consent, granted: boolean): void {
    consentForm.type = consent.type;
    consentForm.granted = granted;
    consentForm.put(privacy.consents.update().url, { preserveScroll: true });
}

const deletion = useForm({ password: '', reason: '', confirm: false });

function requestDeletion(): void {
    deletion.post(privacy.erasure.store().url, {
        preserveScroll: true,
        onSuccess: () => deletion.reset(),
    });
}

/** The date a person was promised, in their own words. */
function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="Your data" />

    <h1 class="sr-only">Your data</h1>

    <div class="flex flex-col space-y-8">
        <Heading
            variant="small"
            title="Your data"
            description="What you have agreed to, a copy of everything we hold about you, and how to delete your account."
        />

        <!-- What you have agreed to -->
        <section class="space-y-4">
            <h2 class="text-base font-medium">What you have agreed to</h2>

            <ul class="divide-border divide-y text-sm">
                <li
                    v-for="consent in required"
                    :key="consent.type"
                    class="flex flex-wrap items-center justify-between gap-3 py-3"
                >
                    <div class="space-y-1">
                        <p class="font-medium">{{ consent.label }}</p>
                        <p class="text-muted-foreground">
                            <template v-if="consent.recorded_at">
                                Agreed
                                {{ formatDate(consent.recorded_at) }}
                                <template v-if="consent.document_version">
                                    · version {{ consent.document_version }}
                                </template>
                            </template>
                            <template v-else>Not recorded</template>
                        </p>
                    </div>

                    <Badge variant="secondary">Required</Badge>
                </li>
            </ul>

            <p class="text-muted-foreground text-sm">
                You cannot withdraw these and keep your account — they are what
                lets us run it. To withdraw, delete your account below.
            </p>

            <Separator />

            <ul class="divide-border divide-y text-sm">
                <li
                    v-for="consent in optional"
                    :key="consent.type"
                    class="flex flex-wrap items-start justify-between gap-3 py-3"
                >
                    <div class="space-y-1">
                        <Label
                            :for="`consent-${consent.type}`"
                            class="font-medium"
                        >
                            {{ consent.label }}
                        </Label>
                        <p class="text-muted-foreground">
                            Offers and news by SMS and email. Turning this off
                            never affects order or security messages.
                        </p>
                    </div>

                    <Checkbox
                        :id="`consent-${consent.type}`"
                        :model-value="consent.granted"
                        :disabled="consentForm.processing"
                        @update:model-value="
                            (value) => toggle(consent, value === true)
                        "
                    />
                </li>
            </ul>
        </section>

        <Separator />

        <!-- A copy of your data -->
        <section class="space-y-4">
            <h2 class="text-base font-medium">Get a copy of your data</h2>

            <p class="text-muted-foreground text-sm">
                Everything MonaFind holds about you: your account, orders,
                messages, reviews and searches. Choose JSON to move it somewhere
                else, or PDF to read it.
            </p>

            <div class="flex flex-wrap gap-3">
                <Button variant="outline" as-child>
                    <a :href="privacy.export.json().url" download>
                        <Download class="h-4 w-4" />
                        Download as JSON
                    </a>
                </Button>

                <Button variant="outline" as-child>
                    <a :href="privacy.export.pdf().url" download>
                        <Download class="h-4 w-4" />
                        Download as PDF
                    </a>
                </Button>
            </div>
        </section>

        <Separator />

        <!-- Deletion -->
        <section class="space-y-4">
            <h2 class="text-base font-medium">Delete your account</h2>

            <!-- Already asked for -->
            <Alert v-if="erasure" variant="default">
                <ShieldAlert class="h-4 w-4" />
                <AlertTitle>
                    Deletion {{ erasure.status_label.toLowerCase() }}
                </AlertTitle>
                <AlertDescription class="space-y-3">
                    <p v-if="erasure.blocked_reason">
                        {{ erasure.blocked_reason }}
                    </p>
                    <p v-else>
                        Your account and personal data will be deleted on
                        {{ formatDate(erasure.erase_after) }}. Nothing has been
                        removed yet.
                    </p>

                    <Button
                        v-if="erasure.cancellable"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="privacy.erasure.cancel(erasure.id)"
                            method="delete"
                            as="button"
                        >
                            Keep my account
                        </Link>
                    </Button>
                </AlertDescription>
            </Alert>

            <!-- Not asked for yet -->
            <template v-else>
                <Alert v-if="blockers.length" variant="default">
                    <TriangleAlert class="h-4 w-4" />
                    <AlertTitle>This will not happen straight away</AlertTitle>
                    <AlertDescription>
                        <ul class="list-disc space-y-1 pl-4">
                            <li v-for="blocker in blockers" :key="blocker">
                                {{ blocker }}
                            </li>
                        </ul>
                    </AlertDescription>
                </Alert>

                <p class="text-muted-foreground text-sm">
                    We will wait {{ graceDays }} days before deleting anything,
                    so you can change your mind. After that your personal
                    details are removed for good and cannot be restored.
                </p>

                <p class="text-muted-foreground text-sm">
                    We have to keep your orders, payments and invoices — Zambian
                    tax law requires it — but they will no longer carry your
                    name, phone number or address.
                    <a
                        :href="retentionUrl"
                        class="underline underline-offset-4"
                    >
                        Read what we keep and for how long.
                    </a>
                </p>

                <form
                    class="max-w-md space-y-4"
                    @submit.prevent="requestDeletion"
                >
                    <div class="space-y-2">
                        <Label for="deletion-reason">
                            Why are you leaving? (optional)
                        </Label>
                        <Input
                            id="deletion-reason"
                            v-model="deletion.reason"
                            type="text"
                            autocomplete="off"
                        />
                        <InputError :message="deletion.errors.reason" />
                    </div>

                    <div class="space-y-2">
                        <Label for="deletion-password">
                            Confirm your password
                        </Label>
                        <Input
                            id="deletion-password"
                            v-model="deletion.password"
                            type="password"
                            autocomplete="current-password"
                            required
                        />
                        <InputError :message="deletion.errors.password" />
                    </div>

                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="deletion-confirm"
                            :model-value="deletion.confirm"
                            @update:model-value="
                                (value) => (deletion.confirm = value === true)
                            "
                        />
                        <Label
                            for="deletion-confirm"
                            class="text-sm leading-snug font-normal"
                        >
                            I understand this cannot be undone.
                        </Label>
                    </div>
                    <InputError :message="deletion.errors.confirm" />

                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="deletion.processing"
                    >
                        Delete my account
                    </Button>
                </form>
            </template>
        </section>
    </div>
</template>
