<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import StepBusiness from '@/components/seller/wizard/StepBusiness.vue';
import StepDocuments from '@/components/seller/wizard/StepDocuments.vue';
import StepPayout from '@/components/seller/wizard/StepPayout.vue';
import StepPolicies from '@/components/seller/wizard/StepPolicies.vue';
import StepReview from '@/components/seller/wizard/StepReview.vue';
import StepType from '@/components/seller/wizard/StepType.vue';
import WizardProgress from '@/components/seller/wizard/WizardProgress.vue';
import type {
    BankOption,
    LabelledOption,
    PlatformMinimumRefund,
    PolicyTypeOption,
    ProvinceOption,
    RegistrationCompleteness,
    RegistrationStepOption,
    RegistrationStepValue,
    SellerRegistrationDraft,
    SellerTypeOption,
} from '@/types';

/**
 * The seller sign-up wizard.
 *
 * One page per step, driven entirely by the server: the step in the URL
 * decides what renders, and the draft carries whatever was typed before. That
 * is what makes it resumable on a phone that ran out of battery halfway
 * through — there is no client-side state to lose.
 */
const props = defineProps<{
    step: RegistrationStepValue;
    steps: RegistrationStepOption[];
    draft: SellerRegistrationDraft;
    completeness: RegistrationCompleteness;
    sellerTypes?: SellerTypeOption[];
    provinces?: ProvinceOption[];
    policyTypes?: PolicyTypeOption[];
    payoutMethods?: LabelledOption[];
    banks?: BankOption[];
    platformMinimumRefund?: PlatformMinimumRefund;
    mapsApiKey?: string | null;
}>();

const current = computed(
    () =>
        props.steps.find((step) => step.value === props.step) ?? props.steps[0],
);

const asksBayCount = computed(() =>
    ['G', 'WO', 'CB'].includes(String(props.draft.answers.type ?? '')),
);
</script>

<template>
    <Head title="Sell on MonaFindAuto" />

    <div class="mx-auto max-w-3xl space-y-8">
        <Heading
            title="Sell on MonaFindAuto"
            :description="`Step ${current.position} of ${steps.length} — ${current.label}`"
        />

        <WizardProgress
            :steps="steps"
            :current="step"
            :furthest="draft.furthest_step"
        />

        <StepType
            v-if="step === 'type' && sellerTypes"
            :types="sellerTypes"
            :selected="(draft.answers.type as never) ?? null"
        />

        <StepBusiness
            v-else-if="step === 'business' && provinces"
            :provinces="provinces"
            :answers="draft.answers"
            :asks-bay-count="asksBayCount"
            :maps-api-key="mapsApiKey"
        />

        <StepPolicies
            v-else-if="
                step === 'policies' && policyTypes && platformMinimumRefund
            "
            :policy-types="policyTypes"
            :answers="draft.answers"
            :platform-minimum-refund="platformMinimumRefund"
        />

        <StepPayout
            v-else-if="step === 'payout' && payoutMethods && banks"
            :accounts="draft.seller?.payout_accounts ?? []"
            :methods="payoutMethods"
            :banks="banks"
            :can-continue="completeness.payout"
        />

        <StepDocuments
            v-else-if="step === 'documents'"
            :documents="draft.seller?.documents ?? []"
            :can-continue="completeness.documents"
        />

        <StepReview
            v-else-if="step === 'review'"
            :draft="draft"
            :completeness="completeness"
        />
    </div>
</template>
