<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, TriangleAlert, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Escrow or direct, per seller.
 *
 * The four criteria are shown individually rather than as one verdict,
 * because an administrator weighing an override needs to see WHICH test
 * failed — "verified but only 14 orders" and "40 orders but a 6% dispute
 * rate" are different risks and only one of them is about patience.
 *
 * The reason field appears the moment the choice goes against the
 * recommendation, so the explanation is written while the decision is being
 * made rather than reconstructed after a bad debt.
 */
const props = defineProps<{
    seller: {
        id: number;
        name: string;
        slug: string;
        paymentMode: string;
        paymentModeLabel: string;
    };
    eligibility: {
        is_eligible: boolean;
        criteria: Array<{
            key: string;
            label: string;
            met: boolean;
            detail: string;
        }>;
    };
    modes: Array<{ value: string; label: string; description: string }>;
    reservePercent: number;
    disputeThresholdPercent: number;
}>();

const form = useForm({
    payment_mode: props.seller.paymentMode,
    reason: '',
});

const needsReason = computed(
    () => form.payment_mode === 'direct' && !props.eligibility.is_eligible,
);

function submit(): void {
    form.put(`/admin/sellers/${props.seller.id}/payment-mode`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Payment mode — ${seller.name}`" />

    <div class="max-w-2xl space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Payment mode</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                {{ seller.name }} is currently on {{ seller.paymentModeLabel }}.
            </p>
        </div>

        <Card>
            <CardHeader>
                <h2 class="font-medium">Direct settlement criteria</h2>
                <p class="text-muted-foreground text-sm">
                    A recommendation, not a gate. You can decide otherwise with
                    a reason.
                </p>
            </CardHeader>
            <CardContent class="space-y-2">
                <div
                    v-for="criterion in eligibility.criteria"
                    :key="criterion.key"
                    class="flex items-center justify-between gap-4 rounded-md border px-3 py-2 text-sm"
                >
                    <span class="flex items-center gap-2">
                        <Check
                            v-if="criterion.met"
                            class="size-4 text-emerald-600"
                            aria-hidden="true"
                        />
                        <X
                            v-else
                            class="text-destructive size-4"
                            aria-hidden="true"
                        />
                        {{ criterion.label }}
                    </span>
                    <span class="text-muted-foreground">{{
                        criterion.detail
                    }}</span>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <h2 class="font-medium">Settlement</h2>
            </CardHeader>
            <CardContent class="space-y-4">
                <label
                    v-for="mode in modes"
                    :key="mode.value"
                    class="flex cursor-pointer gap-3 rounded-md border p-3 transition-colors"
                    :class="
                        form.payment_mode === mode.value
                            ? 'border-primary bg-accent/40'
                            : ''
                    "
                >
                    <input
                        v-model="form.payment_mode"
                        type="radio"
                        :value="mode.value"
                        class="mt-1"
                    />
                    <span>
                        <span class="block font-medium">{{ mode.label }}</span>
                        <span class="text-muted-foreground block text-sm">
                            {{ mode.description }}
                        </span>
                    </span>
                </label>

                <Alert v-if="form.payment_mode === 'direct'">
                    <TriangleAlert class="size-4" aria-hidden="true" />
                    <AlertTitle>What direct settlement means</AlertTitle>
                    <AlertDescription>
                        This seller is paid as orders are paid, less a
                        {{ reservePercent }}% rolling reserve. They revert to
                        escrow automatically if their dispute rate passes
                        {{ disputeThresholdPercent }}%.
                    </AlertDescription>
                </Alert>

                <div v-if="needsReason || form.reason" class="grid gap-2">
                    <Label for="reason">
                        Reason
                        <span v-if="needsReason" class="text-destructive"
                            >*</span
                        >
                    </Label>
                    <Input
                        id="reason"
                        v-model="form.reason"
                        placeholder="Strategic supplier, approved by the MD."
                    />
                    <InputError :message="form.errors.reason" />
                </div>

                <Button :disabled="form.processing" @click="submit"
                    >Save</Button
                >
            </CardContent>
        </Card>
    </div>
</template>
