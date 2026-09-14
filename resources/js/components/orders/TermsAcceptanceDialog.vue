<script setup lang="ts">
import { Check, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import type { CheckoutSellerGroup, PlatformTerms } from '@/types';

/**
 * The blocking terms modal: one per shop, before that shop's order exists.
 *
 * Three decisions are load-bearing here.
 *
 * The policies are rendered in full rather than linked. A buyer who has to
 * leave checkout to read a refund policy does not read it, and an acceptance
 * record for text nobody read is worse than none at all.
 *
 * The platform's minimum refund rule sits inside the refund policy's own
 * panel rather than in a footnote. It overrides whatever the seller wrote,
 * and the only place that fact is any use is next to the wording it
 * overrides.
 *
 * Accepting emits the policy ids AND versions that were on screen. The server
 * checks them against what is current and refuses the order if the seller
 * republished while the buyer was reading — which is the difference between a
 * consent record and a checkbox.
 */
const props = defineProps<{
    open: boolean;
    group: CheckoutSellerGroup;
    platformTerms: PlatformTerms;
}>();

const emit = defineEmits<{
    (event: 'update:open', value: boolean): void;
    (event: 'accept', value: { policy_id: number; version: number }[]): void;
}>();

const readToEnd = ref(false);

const accept = (): void => {
    emit(
        'accept',
        props.group.policies.map((policy) => ({
            policy_id: policy.policy_id,
            version: policy.version,
        })),
    );

    emit('update:open', false);
};

/**
 * Whether the reader has reached the bottom.
 *
 * Not a gate on the button — a scroll listener is not consent and pretending
 * otherwise makes the record weaker, not stronger. It only moves the button
 * out of "keep reading" into its accepting state.
 */
const onScroll = (event: Event): void => {
    const el = event.target as HTMLElement;

    if (el.scrollHeight - el.scrollTop - el.clientHeight < 48) {
        readToEnd.value = true;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogScrollContent class="sm:max-w-2xl" @scroll.capture="onScroll">
            <DialogHeader>
                <DialogTitle>
                    Ordering from {{ group.seller.business_name }}
                </DialogTitle>
                <DialogDescription>
                    These are the terms this order is placed under. We record
                    the exact version you accept.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-5 text-sm">
                <section
                    v-for="policy in group.policies"
                    :key="policy.policy_id"
                    class="space-y-2"
                >
                    <header class="flex items-baseline justify-between gap-2">
                        <h3 class="font-semibold">{{ policy.label }}</h3>
                        <span class="text-muted-foreground text-xs">
                            Version {{ policy.version }}
                        </span>
                    </header>

                    <p class="text-muted-foreground whitespace-pre-line">
                        {{ policy.body }}
                    </p>

                    <!--
                        MonaFind's floor, beside the wording it overrides.
                        A buyer must not be able to be talked out of it by
                        the seller's own paragraph.
                    -->
                    <Alert v-if="policy.shows_platform_minimum">
                        <ShieldCheck class="size-4" aria-hidden="true" />
                        <AlertTitle>Whatever this policy says</AlertTitle>
                        <AlertDescription>
                            {{ platformTerms.minimum_refund_statement }}
                        </AlertDescription>
                    </Alert>
                </section>

                <p v-if="!group.policies.length" class="text-muted-foreground">
                    This seller has not published its own policies. MonaFind's
                    terms below govern this order in full.
                </p>

                <Separator />

                <section class="space-y-2">
                    <header class="flex items-baseline justify-between gap-2">
                        <h3 class="font-semibold">MonaFindAuto terms</h3>
                        <span class="text-muted-foreground text-xs">
                            Version {{ platformTerms.version }}
                        </span>
                    </header>

                    <p class="text-muted-foreground whitespace-pre-line">
                        {{ platformTerms.body }}
                    </p>
                </section>
            </div>

            <DialogFooter class="gap-2">
                <Button
                    variant="ghost"
                    type="button"
                    @click="emit('update:open', false)"
                >
                    Not now
                </Button>

                <Button type="button" data-test="accept-terms" @click="accept">
                    <Check class="size-4" aria-hidden="true" />
                    {{
                        readToEnd
                            ? 'I accept these terms'
                            : 'Accept and continue'
                    }}
                </Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
