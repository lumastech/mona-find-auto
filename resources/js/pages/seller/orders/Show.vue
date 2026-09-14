<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    FileText,
    PackageCheck,
    Truck,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import Money from '@/components/Money.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import OrderTimeline from '@/components/orders/OrderTimeline.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import RatingPromptCard from '@/components/ratings/RatingPromptCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import sellerOrders from '@/routes/seller/orders';
import type { OrderSummary, RatingPrompt } from '@/types';

/**
 * One order, from the shop's side.
 *
 * The action shown is decided by where the order actually is, so there is
 * only ever one obvious next step rather than a row of buttons three of which
 * would be refused. Which state "mark ready" produces is the ORDER's business
 * — a pickup order goes to the counter, a delivery order goes out — so there
 * is one route for both and no way to dispatch something a buyer is coming to
 * collect.
 *
 * The payout panel reads the terms the order was frozen with rather than the
 * shop's current ones. A commission change next month must not restate what
 * this sale earned.
 */
const props = defineProps<{
    order: OrderSummary;
    /*
     * The shop's rating of the buyer, offered once the order completes. It is
     * private, and the card says so before anybody types.
     */
    ratingPrompts: RatingPrompt[];
    payout: {
        commission_ngwee: number;
        payout_ngwee: number;
        terms: string | null;
        payment_mode: string | null;
        payment_mode_label: string | null;
    };
}>();

const act = (url: string): void => {
    router.post(url, {}, { preserveScroll: true });
};

/** The single next step this order is waiting on, if any. */
const nextStep = computed(() => {
    switch (props.order.status) {
        case 'paid':
            return {
                label: 'Confirm this order',
                url: sellerOrders.confirm(props.order.number).url,
                icon: Check,
            };
        case 'seller_confirmed':
            return {
                label:
                    props.order.fulfilment_method === 'pickup'
                        ? 'Ready for collection'
                        : 'Dispatch it',
                url: sellerOrders.ready(props.order.number).url,
                icon:
                    props.order.fulfilment_method === 'pickup'
                        ? PackageCheck
                        : Truck,
            };
        case 'ready_for_pickup':
            return {
                label: 'The buyer collected it',
                url: sellerOrders.handedOver(props.order.number).url,
                icon: PackageCheck,
            };
        case 'dispatched':
            return {
                label: 'It was delivered',
                url: sellerOrders.handedOver(props.order.number).url,
                icon: Truck,
            };
        default:
            return null;
    }
});

const canCancel = computed(() =>
    ['paid', 'seller_confirmed', 'ready_for_pickup', 'dispatched'].includes(
        props.order.status,
    ),
);
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
                <Badge variant="outline">{{ order.fulfilment_label }}</Badge>
            </div>
        </header>

        <Alert
            v-if="order.dispute && order.dispute.status !== 'resolved'"
            variant="destructive"
        >
            <AlertTriangle class="size-4" aria-hidden="true" />
            <AlertTitle>The buyer reported a problem</AlertTitle>
            <AlertDescription>
                {{ order.dispute.reason_label }} — {{ order.dispute.details }}
                <span class="mt-1 block">
                    No payout will be released until MonaFind has settled this.
                </span>
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <h2 class="font-semibold">To pick</h2>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div
                            v-for="item in order.items"
                            :key="item.id"
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div>
                                <p class="font-medium">{{ item.name }}</p>
                                <p class="text-muted-foreground text-xs">
                                    <span v-if="item.sku"
                                        >{{ item.sku }} ·
                                    </span>
                                    × {{ item.quantity }}
                                </p>
                            </div>
                            <Money :amount="item.total_ngwee" />
                        </div>

                        <Separator />

                        <div v-if="order.delivery_address" class="space-y-1">
                            <p class="font-medium">Deliver to</p>
                            <p class="text-muted-foreground">
                                {{ order.delivery_address.recipient_name }},
                                {{ order.delivery_address.recipient_phone }}
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

                        <p v-else class="text-muted-foreground">
                            The buyer is collecting. Ask for order number
                            {{ order.number }}.
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="order.timeline?.length">
                    <CardHeader>
                        <h2 class="font-semibold">History</h2>
                    </CardHeader>
                    <CardContent>
                        <OrderTimeline :entries="order.timeline" />
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-4">
                <Card>
                    <CardContent class="space-y-2 py-4">
                        <Button
                            v-if="nextStep"
                            class="w-full"
                            data-test="next-step"
                            @click="act(nextStep.url)"
                        >
                            <component
                                :is="nextStep.icon"
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ nextStep.label }}
                        </Button>

                        <Button as-child variant="outline" class="w-full">
                            <a
                                :href="
                                    sellerOrders.packingSlip(order.number).url
                                "
                            >
                                <FileText class="size-4" aria-hidden="true" />
                                Packing slip
                            </a>
                        </Button>

                        <Button
                            v-if="canCancel"
                            variant="ghost"
                            class="text-destructive w-full"
                            @click="act(sellerOrders.cancel(order.number).url)"
                        >
                            <X class="size-4" aria-hidden="true" />
                            Cancel and refund
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <h2 class="font-semibold">What you will be paid</h2>
                    </CardHeader>
                    <CardContent class="space-y-2 text-sm">
                        <dl class="space-y-1">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">
                                    Order value
                                </dt>
                                <dd><Money :amount="order.total_ngwee" /></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">
                                    MonaFind commission
                                </dt>
                                <dd>
                                    <Money :amount="payout.commission_ngwee" />
                                </dd>
                            </div>
                            <div class="flex justify-between font-semibold">
                                <dt>Your payout</dt>
                                <dd><Money :amount="payout.payout_ngwee" /></dd>
                            </div>
                        </dl>

                        <p
                            v-if="payout.terms"
                            class="text-muted-foreground text-xs"
                        >
                            {{ payout.terms }}, as agreed when this order was
                            paid.
                        </p>

                        <p
                            v-if="payout.payment_mode_label"
                            class="text-muted-foreground text-xs"
                        >
                            Payment mode: {{ payout.payment_mode_label }}.
                        </p>
                    </CardContent>
                </Card>

                <RatingPromptCard
                    v-for="prompt in ratingPrompts"
                    :key="prompt.direction"
                    :prompt="prompt"
                    :action="sellerOrders.ratings.store(order.number).url"
                />
            </div>
        </div>
    </div>
</template>
