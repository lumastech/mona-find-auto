<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import sellers from '@/routes/sellers';
import type { PlatformMinimumRefund, PolicyTypeOption } from '@/types';

/**
 * Step three: the policies buyers accept at checkout.
 *
 * MonaFind's own refund floor sits beside the refund box rather than in a
 * terms page nobody opens: a seller cannot contract out of it, so they should
 * see it while they are writing the policy it overrides.
 */
const props = defineProps<{
    policyTypes: PolicyTypeOption[];
    answers: Record<string, unknown>;
    platformMinimumRefund: PlatformMinimumRefund;
}>();

const existing = (props.answers.policies ?? {}) as Record<string, string>;
</script>

<template>
    <Form
        v-bind="sellers.register.store.form({ step: 'policies' })"
        v-slot="{ errors, processing }"
        class="space-y-8"
    >
        <div
            v-for="policy in policyTypes"
            :key="policy.value"
            class="space-y-2"
        >
            <Label :for="`policy-${policy.value}`">
                {{ policy.label }}
                <span
                    v-if="!policy.required"
                    class="text-muted-foreground font-normal"
                >
                    (optional)
                </span>
            </Label>
            <p class="text-muted-foreground text-sm">{{ policy.guidance }}</p>

            <textarea
                :id="`policy-${policy.value}`"
                :name="`policies[${policy.value}]`"
                rows="5"
                :value="existing[policy.value] ?? ''"
                :required="policy.required"
                class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
            />

            <InputError :message="errors[`policies.${policy.value}`]" />

            <div
                v-if="policy.shows_platform_minimum"
                class="bg-muted/50 flex gap-3 rounded-lg border p-3"
            >
                <ShieldCheck
                    class="text-primary mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <p class="text-muted-foreground text-sm">
                    <span class="text-foreground font-medium">
                        MonaFind minimum:
                    </span>
                    {{ platformMinimumRefund.statement }}
                </p>
            </div>
        </div>

        <p class="text-muted-foreground text-sm">
            Editing a policy later publishes a new version. Buyers stay bound to
            the version they accepted when they ordered.
        </p>

        <Button type="submit" :disabled="processing">
            <Spinner v-if="processing" class="size-4" />
            Save and continue
        </Button>
    </Form>
</template>
