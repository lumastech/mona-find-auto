<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileSignature } from '@lucide/vue';
import Money from '@/components/Money.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import OrderTimeline from '@/components/orders/OrderTimeline.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import type { OrderSummary } from '@/types';

/**
 * One order in full, for staff.
 *
 * The acceptance panel is the reason this screen exists rather than being a
 * wider version of the seller's. When a seller quotes their own refund policy
 * at a buyer in a dispute, somebody has to establish which VERSION the buyer
 * actually agreed to — and since seller policies are published as versions
 * rather than edited, that answer is recoverable exactly.
 *
 * The money panel reads the snapshot the order carries, not today's defaults.
 */
defineProps<{
    order: OrderSummary;
    money: {
        commission_ngwee: number;
        payout_ngwee: number;
        payment_mode: string | null;
        monetisation: Record<string, unknown> | null;
        snapshot_at: string | null;
    };
    acceptance: {
        accepted_at: string;
        ip_address: string | null;
        user_agent: string | null;
        platform_terms_version: string | null;
        minimum_refund_days: number;
        minimum_refund_statement: string;
        policies: {
            policy_id: number;
            type: string;
            version: number;
            effective_from: string | null;
        }[];
    } | null;
}>();
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
                <Badge v-if="money.payment_mode" variant="outline">
                    {{ money.payment_mode }}
                </Badge>
            </div>
            <p class="text-muted-foreground text-sm">
                {{ order.seller.business_name }} · total
                <Money :amount="order.total_ngwee" />
            </p>
        </header>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <h2 class="font-semibold">Lines</h2>
                    </CardHeader>
                    <CardContent class="space-y-2 text-sm">
                        <div
                            v-for="item in order.items"
                            :key="item.id"
                            class="flex justify-between gap-3"
                        >
                            <span>
                                {{ item.name }}
                                <span class="text-muted-foreground"
                                    >× {{ item.quantity }}</span
                                >
                            </span>
                            <Money :amount="item.total_ngwee" />
                        </div>
                    </CardContent>
                </Card>

                <!--
                    What the buyer agreed to, by version. The point of the
                    screen when a policy is in dispute.
                -->
                <Card>
                    <CardHeader class="gap-1">
                        <h2 class="flex items-center gap-2 font-semibold">
                            <FileSignature class="size-4" aria-hidden="true" />
                            Terms accepted
                        </h2>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <p v-if="!acceptance" class="text-muted-foreground">
                            No acceptance was recorded for this order.
                        </p>

                        <template v-else>
                            <dl class="grid gap-1 sm:grid-cols-2">
                                <div>
                                    <dt class="text-muted-foreground text-xs">
                                        Accepted
                                    </dt>
                                    <dd>
                                        {{
                                            new Date(
                                                acceptance.accepted_at,
                                            ).toLocaleString()
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground text-xs">
                                        From
                                    </dt>
                                    <dd>
                                        {{ acceptance.ip_address ?? 'Unknown' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground text-xs">
                                        Platform terms
                                    </dt>
                                    <dd>
                                        Version
                                        {{ acceptance.platform_terms_version }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground text-xs">
                                        Minimum refund window
                                    </dt>
                                    <dd>
                                        {{ acceptance.minimum_refund_days }}
                                        days
                                    </dd>
                                </div>
                            </dl>

                            <Separator />

                            <ul class="space-y-1">
                                <li
                                    v-for="policy in acceptance.policies"
                                    :key="policy.policy_id"
                                    class="flex justify-between"
                                >
                                    <span class="capitalize">{{
                                        policy.type
                                    }}</span>
                                    <span class="text-muted-foreground">
                                        version {{ policy.version }}
                                    </span>
                                </li>
                            </ul>

                            <p class="text-muted-foreground text-xs italic">
                                {{ acceptance.minimum_refund_statement }}
                            </p>

                            <p class="text-muted-foreground text-xs">
                                {{ acceptance.user_agent }}
                            </p>
                        </template>
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
                    <CardHeader>
                        <h2 class="font-semibold">Money</h2>
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
                                    Commission
                                </dt>
                                <dd>
                                    <Money :amount="money.commission_ngwee" />
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">
                                    Seller payout
                                </dt>
                                <dd><Money :amount="money.payout_ngwee" /></dd>
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

                        <p
                            v-if="money.snapshot_at"
                            class="text-muted-foreground text-xs"
                        >
                            Terms frozen
                            {{ new Date(money.snapshot_at).toLocaleString() }}.
                            Later changes to the seller's terms do not apply.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
