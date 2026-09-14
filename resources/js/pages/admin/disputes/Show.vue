<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Gavel, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import OrderTimeline from '@/components/orders/OrderTimeline.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import adminDisputes from '@/routes/admin/disputes';
import adminOrders from '@/routes/admin/orders';
import type { DisputeRow, OrderTimelineEntry, ResolutionOption } from '@/types';

/**
 * Deciding one dispute.
 *
 * The resolution is not a note — it is the instruction the ledger acts on, so
 * it is a closed set of three with an amount beside the one that needs one.
 * The explanation is mandatory for all three, including releasing to the
 * seller: both parties are shown it, and "your dispute was closed" with no
 * reason is how a platform loses a buyer.
 */
const props = defineProps<{
    dispute: DisputeRow;
    timeline: OrderTimelineEntry[];
    resolutions: ResolutionOption[];
}>();

const form = useForm({
    resolution: props.resolutions[0]?.value ?? 'release',
    refund_amount: (props.dispute.order.total_ngwee / 100).toFixed(2),
    note: '',
});

const chosen = computed(() =>
    props.resolutions.find((option) => option.value === form.resolution),
);

const claim = (): void => {
    router.post(
        adminDisputes.claim(props.dispute.id).url,
        {},
        { preserveScroll: true },
    );
};

const submit = (): void => {
    form.post(adminDisputes.resolve(props.dispute.id).url);
};
</script>

<template>
    <Head :title="`Dispute on ${dispute.order.number}`" />

    <div class="space-y-6">
        <header class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ dispute.order.number }}
                </h1>
                <Badge :variant="dispute.status_variant">{{
                    dispute.status_label
                }}</Badge>
                <Badge variant="outline">{{ dispute.reason_label }}</Badge>
            </div>
            <p class="text-muted-foreground text-sm">
                {{ dispute.opened_by }} against {{ dispute.order.seller }} ·
                <Money :amount="dispute.order.total_ngwee" />
            </p>
        </header>

        <Alert v-if="dispute.covered_by_platform_minimum">
            <ShieldCheck class="size-4" aria-hidden="true" />
            <AlertTitle
                >MonaFind's minimum refund rule covers this reason</AlertTitle
            >
            <AlertDescription>
                A wrong, damaged, misdescribed or counterfeit part is refundable
                whatever the seller's own policy says.
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <h2 class="font-semibold">What the buyer says</h2>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm whitespace-pre-line">
                            {{ dispute.details }}
                        </p>

                        <div
                            v-if="dispute.photos.length"
                            class="grid grid-cols-2 gap-2 sm:grid-cols-3"
                        >
                            <a
                                v-for="photo in dispute.photos"
                                :key="photo.id"
                                :href="photo.url"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <img
                                    :src="photo.url"
                                    :alt="photo.name"
                                    class="aspect-square w-full rounded-md border object-cover"
                                    loading="lazy"
                                />
                            </a>
                        </div>

                        <p v-else class="text-muted-foreground text-sm">
                            No photographs were attached.
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="timeline.length">
                    <CardHeader>
                        <h2 class="font-semibold">Order history</h2>
                    </CardHeader>
                    <CardContent>
                        <OrderTimeline :entries="timeline" />
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-4">
                <Card v-if="dispute.status === 'resolved'">
                    <CardHeader>
                        <h2 class="font-semibold">Decided</h2>
                    </CardHeader>
                    <CardContent class="space-y-2 text-sm">
                        <p class="font-medium">
                            {{ dispute.resolution_label }}
                        </p>
                        <p v-if="dispute.refund_ngwee">
                            Refunded <Money :amount="dispute.refund_ngwee" />
                        </p>
                        <p class="text-muted-foreground">
                            {{ dispute.resolution_note }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ dispute.resolved_by }} ·
                            {{
                                dispute.resolved_at
                                    ? new Date(
                                          dispute.resolved_at,
                                      ).toLocaleString()
                                    : ''
                            }}
                        </p>
                    </CardContent>
                </Card>

                <Card v-else>
                    <CardHeader class="gap-1">
                        <h2 class="flex items-center gap-2 font-semibold">
                            <Gavel class="size-4" aria-hidden="true" />
                            Decide
                        </h2>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <Button
                            v-if="dispute.status === 'open'"
                            variant="outline"
                            size="sm"
                            class="w-full"
                            @click="claim"
                        >
                            I am looking at this
                        </Button>

                        <form class="space-y-4" @submit.prevent="submit">
                            <fieldset class="space-y-2">
                                <legend class="text-sm font-medium">
                                    Outcome
                                </legend>

                                <label
                                    v-for="option in resolutions"
                                    :key="option.value"
                                    class="hover:bg-accent flex cursor-pointer items-start gap-3 rounded-md border p-3"
                                    :class="
                                        form.resolution === option.value
                                            ? 'border-primary'
                                            : ''
                                    "
                                >
                                    <input
                                        v-model="form.resolution"
                                        type="radio"
                                        name="resolution"
                                        :value="option.value"
                                        class="mt-1"
                                    />
                                    <span class="text-sm">
                                        <span class="block font-medium">{{
                                            option.label
                                        }}</span>
                                        <span class="text-muted-foreground">
                                            {{ option.description }}
                                        </span>
                                    </span>
                                </label>

                                <InputError :message="form.errors.resolution" />
                            </fieldset>

                            <div v-if="chosen?.needs_amount" class="space-y-1">
                                <Label for="refund_amount"
                                    >Refund to the buyer (K)</Label
                                >
                                <Input
                                    id="refund_amount"
                                    v-model="form.refund_amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    inputmode="decimal"
                                />
                                <InputError
                                    :message="form.errors.refund_amount"
                                />
                            </div>

                            <div class="space-y-1">
                                <Label for="note">Why</Label>
                                <textarea
                                    id="note"
                                    v-model="form.note"
                                    rows="4"
                                    class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                                    placeholder="Both the buyer and the seller are shown this."
                                />
                                <InputError :message="form.errors.note" />
                            </div>

                            <Button
                                type="submit"
                                class="w-full"
                                :disabled="form.processing"
                            >
                                Resolve
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Button as-child variant="ghost" size="sm" class="w-full">
                    <a :href="adminOrders.show(dispute.order.number).url">
                        Open the full order
                    </a>
                </Button>
            </div>
        </div>
    </div>
</template>
