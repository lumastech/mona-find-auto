<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { Check, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import sellers from '@/routes/sellers';
import type {
    RegistrationCompleteness,
    RegistrationStepValue,
    SellerRegistrationDraft,
} from '@/types';

/**
 * Step six: what MonaFind is about to receive.
 *
 * Each line is a link back to the step that fills it, because the useful
 * thing to do about "documents: missing" is to go and upload them.
 */
const props = defineProps<{
    draft: SellerRegistrationDraft;
    completeness: RegistrationCompleteness;
}>();

const rows: {
    key: keyof RegistrationCompleteness;
    label: string;
    step: RegistrationStepValue;
    detail: string;
}[] = [
    {
        key: 'type',
        label: 'Business type',
        step: 'type',
        detail: props.draft.seller?.type_label ?? 'Not chosen',
    },
    {
        key: 'business',
        label: 'Business details',
        step: 'business',
        detail: props.draft.seller
            ? `${props.draft.seller.business_name} — ${props.draft.seller.single_line}`
            : 'Not filled in',
    },
    {
        key: 'policies',
        label: 'Policies',
        step: 'policies',
        detail: `${props.draft.seller?.policies.length ?? 0} published`,
    },
    {
        key: 'payout',
        label: 'Payout account',
        step: 'payout',
        detail:
            props.draft.seller?.payout_accounts.find((a) => a.is_default)
                ?.display_name ?? 'None confirmed',
    },
    {
        key: 'documents',
        label: 'Documents',
        step: 'documents',
        detail: `${props.draft.seller?.documents.filter((d) => d.uploaded).length ?? 0} uploaded`,
    },
];

const ready = computed(() => Object.values(props.completeness).every(Boolean));
</script>

<template>
    <div class="space-y-6">
        <ul class="divide-y rounded-lg border">
            <li
                v-for="row in rows"
                :key="row.key"
                class="flex flex-wrap items-center gap-3 p-4"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-full"
                    :class="
                        completeness[row.key]
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-destructive/10 text-destructive'
                    "
                >
                    <component
                        :is="completeness[row.key] ? Check : X"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block font-medium">{{ row.label }}</span>
                    <span class="text-muted-foreground block truncate text-sm">
                        {{ row.detail }}
                    </span>
                </span>

                <Link
                    :href="sellers.register.step({ step: row.step })"
                    class="text-sm underline-offset-4 hover:underline"
                >
                    {{ completeness[row.key] ? 'Change' : 'Finish' }}
                </Link>
            </li>
        </ul>

        <div
            v-if="draft.seller && !draft.seller.registration_number"
            class="bg-muted/50 rounded-lg border p-4 text-sm"
        >
            <p class="font-medium">You have not given us a PACRA number yet.</p>
            <p class="text-muted-foreground mt-1">
                You can still sell. We cannot grant the Verified badge until you
                add it, so buyers will see "Not yet verified" on your listings.
            </p>
        </div>

        <Form
            v-bind="sellers.register.submit.form()"
            v-slot="{ errors, processing }"
            class="space-y-3"
        >
            <InputError :message="errors.submit" />

            <Button type="submit" :disabled="processing || !ready">
                <Spinner v-if="processing" class="size-4" />
                Send my application
            </Button>

            <p class="text-muted-foreground text-sm">
                We will review your documents and may call the number you gave
                us. You can keep editing your shop while we do.
            </p>
        </Form>
    </div>
</template>
