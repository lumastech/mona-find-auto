<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Check, FileText, Phone } from '@lucide/vue';
import Money from '@/components/Money.vue';
import ContactThreadButton from '@/components/messaging/ContactThreadButton.vue';
import DisputeDialog from '@/components/orders/DisputeDialog.vue';
import RatingPromptCard from '@/components/ratings/RatingPromptCard.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import OrderTimeline from '@/components/orders/OrderTimeline.vue';
import PickupMap from '@/components/orders/PickupMap.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import orders from '@/routes/orders';
import sellers from '@/routes/sellers';
import type { OrderSummary, RatingPrompt } from '@/types';

/**
 * One order, from the buyer's side.
 *
 * The two buttons are the whole point of the page: confirm receipt, which
 * releases the seller's money, and report a problem, which stops it. Neither
 * is rendered from a status comparison — `order.can` is answered by the
 * server from the same rules the state machine enforces, so this page cannot
 * offer an action the server would refuse.
 *
 * When an auto-completion deadline is running the page says so plainly.
 * A buyer who does not know their money releases on Thursday cannot act
 * before Thursday.
 */
const props = defineProps<{
    order: OrderSummary;
    /* Empty until the order completes, and again once the review is left. */
    ratingPrompts: RatingPrompt[];
    disputeReasons: { value: string; label: string; guidance: string }[];
    mapsApiKey: string | null;
}>();

const confirmReceipt = (): void => {
    router.post(
        orders.confirmReceipt(props.order.number).url,
        {},
        { preserveScroll: true },
    );
};

const deadline = (iso: string): string =>
    new Date(iso).toLocaleString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'short',
    });
</script>

<template>
    <Head :title="`Order ${order.number}`" />

    <div class="space-y-6">
        <header class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ order.number }}
                </h1>
                <OrderStatusBadge :order="order" />
            </div>
            <p class="text-muted-foreground text-sm">
                {{ order.status_description }}
            </p>
        </header>

        <Alert
            v-if="order.dispute && order.dispute.status !== 'resolved'"
            variant="destructive"
        >
            <AlertTriangle class="size-4" aria-hidden="true" />
            <AlertTitle>You reported a problem with this order</AlertTitle>
            <AlertDescription>
                {{ order.dispute.reason_label }} — MonaFind is looking at it.
                Nothing will be paid to the seller until it is settled.
            </AlertDescription>
        </Alert>

        <Alert v-else-if="order.can.confirm_receipt && order.auto_complete_at">
            <Check class="size-4" aria-hidden="true" />
            <AlertTitle>Check the part</AlertTitle>
            <AlertDescription>
                If we do not hear from you by
                {{ deadline(order.auto_complete_at) }}, we will treat this order
                as complete and pay the seller.
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card>
                    <CardHeader class="gap-1">
                        <h2 class="font-semibold">What you bought</h2>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div
                            v-for="item in order.items"
                            :key="item.id"
                            class="flex flex-wrap items-start justify-between gap-3 text-sm"
                        >
                            <div class="space-y-1">
                                <p class="font-medium">{{ item.name }}</p>
                                <p class="text-muted-foreground text-xs">
                                    <span v-if="item.sku"
                                        >{{ item.sku }} ·
                                    </span>
                                    × {{ item.quantity }} at
                                    <Money :amount="item.unit_price_ngwee" />
                                </p>
                                <div class="flex flex-wrap gap-1">
                                    <Badge
                                        v-if="item.condition_label"
                                        variant="outline"
                                    >
                                        {{ item.condition_label }}
                                    </Badge>
                                    <Badge
                                        v-if="item.inspection_label"
                                        variant="outline"
                                    >
                                        {{ item.inspection_label }}
                                    </Badge>
                                    <Badge
                                        v-if="item.was_quoted"
                                        variant="secondary"
                                    >
                                        Quoted price
                                    </Badge>
                                </div>
                            </div>

                            <Money
                                :amount="item.total_ngwee"
                                class="font-medium"
                            />
                        </div>

                        <Separator />

                        <dl class="space-y-1 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Parts</dt>
                                <dd>
                                    <Money :amount="order.items_total_ngwee" />
                                </dd>
                            </div>
                            <div
                                v-if="order.delivery_fee_ngwee"
                                class="flex justify-between"
                            >
                                <dt class="text-muted-foreground">Delivery</dt>
                                <dd>
                                    <Money :amount="order.delivery_fee_ngwee" />
                                </dd>
                            </div>
                            <div class="flex justify-between font-semibold">
                                <dt>Total</dt>
                                <dd><Money :amount="order.total_ngwee" /></dd>
                            </div>
                            <div
                                v-if="order.refunded_ngwee"
                                class="flex justify-between"
                            >
                                <dt class="text-muted-foreground">Refunded</dt>
                                <dd>
                                    <Money :amount="order.refunded_ngwee" />
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card v-if="order.timeline?.length">
                    <CardHeader>
                        <h2 class="font-semibold">What has happened</h2>
                    </CardHeader>
                    <CardContent>
                        <OrderTimeline :entries="order.timeline" />
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <h2 class="font-semibold">
                            {{ order.fulfilment_label }}
                        </h2>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <PickupMap
                            v-if="order.fulfilment_method === 'pickup'"
                            :name="order.seller.business_name"
                            :address="order.seller.address"
                            :latitude="order.seller.latitude"
                            :longitude="order.seller.longitude"
                            :api-key="mapsApiKey"
                        />

                        <div
                            v-else-if="order.delivery_address"
                            class="space-y-1"
                        >
                            <p class="font-medium">
                                {{ order.delivery_address.recipient_name }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ order.delivery_address.street }},
                                {{ order.delivery_address.city }}
                            </p>
                            <p
                                v-if="order.delivery_instructions"
                                class="text-muted-foreground italic"
                            >
                                {{ order.delivery_instructions }}
                            </p>
                        </div>

                        <Separator />

                        <div class="space-y-1">
                            <Link
                                :href="sellers.show(order.seller.slug).url"
                                class="font-medium hover:underline"
                            >
                                {{ order.seller.business_name }}
                            </Link>
                            <p
                                class="text-muted-foreground flex items-center gap-2"
                            >
                                <Phone class="size-3" aria-hidden="true" />
                                {{ order.seller.phone }}
                            </p>

                            <!--
                                Beside the phone number, not instead of it.
                                What is said here is on the record, which is
                                what a dispute is decided on — a conversation
                                that moved to WhatsApp is one neither side can
                                produce.
                            -->
                            <ContactThreadButton
                                subject-type="order"
                                :subject-id="order.id"
                                label="Message the seller"
                                variant="outline"
                                class="mt-2 w-full"
                            />
                        </div>
                    </CardContent>
                </Card>

                <RatingPromptCard
                    v-for="prompt in ratingPrompts"
                    :key="prompt.direction"
                    :prompt="prompt"
                    :action="orders.ratings.store(order.number).url"
                    allow-photos
                />

                <Card>
                    <CardContent class="space-y-2 py-4">
                        <Button
                            v-if="order.can.confirm_receipt"
                            class="w-full"
                            data-test="confirm-receipt"
                            @click="confirmReceipt"
                        >
                            <Check class="size-4" aria-hidden="true" />
                            I have it and it is right
                        </Button>

                        <DisputeDialog
                            v-if="order.can.open_dispute"
                            :order="order"
                            :reasons="disputeReasons"
                        />

                        <Button
                            v-if="order.can.download_receipt"
                            as-child
                            variant="outline"
                            class="w-full"
                        >
                            <a :href="orders.receipt(order.number).url">
                                <FileText class="size-4" aria-hidden="true" />
                                Download receipt
                            </a>
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
