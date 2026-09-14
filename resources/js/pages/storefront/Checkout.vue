<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BadgeCheck,
    Check,
    CreditCard,
    MapPin,
    Smartphone,
    Truck,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Money from '@/components/Money.vue';
import PickupMap from '@/components/orders/PickupMap.vue';
import TermsAcceptanceDialog from '@/components/orders/TermsAcceptanceDialog.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import addresses from '@/routes/addresses';
import cartRoutes from '@/routes/cart';
import checkoutRoutes from '@/routes/checkout';
import type {
    Checkout,
    CheckoutSelection,
    CheckoutSellerGroup,
    FulfilmentMethodValue,
    PaymentMethodValue,
} from '@/types';

/**
 * Checkout, one panel per shop.
 *
 * The grouping is the whole design. A buyer fixing one car buys the filter
 * from a parts shop and the wing mirror from a breaker across town: two
 * dispatches, two sets of terms, two fulfilment decisions — and one payment,
 * which is the single figure at the bottom. A combined delivery choice would
 * be describing a delivery nobody is going to make.
 *
 * Nothing can be paid until every shop's terms have been accepted, and the
 * button says which shops are still outstanding rather than sitting disabled
 * with no explanation. What is posted back for each shop is the policy ids
 * and VERSIONS that were on screen; the server refuses the order if a seller
 * republished while the buyer was reading, which is what makes the acceptance
 * record evidence rather than a checkbox.
 */
const props = defineProps<{
    checkout: Checkout;
    mapsApiKey: string | null;
}>();

type SelectionState = {
    fulfilment_method: FulfilmentMethodValue;
    user_address_id: number | null;
    delivery_instructions: string;
    accepted_policies: { policy_id: number; version: number }[];
};

/** One row of local state per shop, opened on whatever that shop can do. */
const selections = reactive<Record<number, SelectionState>>(
    Object.fromEntries(
        props.checkout.groups.map((group) => [
            group.seller.id,
            {
                fulfilment_method: group.default_method,
                user_address_id: props.checkout.default_address_id,
                delivery_instructions: '',
                accepted_policies: [],
            },
        ]),
    ),
);

const paymentMethod = ref<PaymentMethodValue>(
    (props.checkout.payment_methods[0]?.value as PaymentMethodValue) ?? 'card',
);

const termsOpenFor = ref<number | null>(null);

/**
 * `checkout` is not one of the posted fields — the server returns it under
 * that key when the cart or a seller's terms moved between rendering this
 * page and submitting it, which is a whole-form problem rather than a field
 * one.
 */
const form = useForm<{
    payment_method: PaymentMethodValue;
    selections: CheckoutSelection[];
    checkout?: never;
}>({
    payment_method: paymentMethod.value,
    selections: [],
});

const hasAccepted = (group: CheckoutSellerGroup): boolean =>
    selections[group.seller.id].accepted_policies.length > 0 ||
    (group.policies.length === 0 &&
        selections[group.seller.id].accepted_policies !== null &&
        acceptedEmpty.value.has(group.seller.id));

/**
 * A shop with no published policies still needs an explicit acceptance — the
 * platform terms alone are what the order runs on — so acceptance is tracked
 * separately from "has policy ids".
 */
const acceptedEmpty = ref<Set<number>>(new Set());

const accept = (
    group: CheckoutSellerGroup,
    policies: { policy_id: number; version: number }[],
): void => {
    selections[group.seller.id].accepted_policies = policies;
    acceptedEmpty.value = new Set(acceptedEmpty.value).add(group.seller.id);
};

const outstanding = computed(() =>
    props.checkout.groups.filter((group) => !hasAccepted(group)),
);

const needsAddress = (group: CheckoutSellerGroup): boolean =>
    selections[group.seller.id].fulfilment_method === 'delivery';

const missingAddress = computed(() =>
    props.checkout.groups.filter(
        (group) =>
            needsAddress(group) &&
            selections[group.seller.id].user_address_id === null,
    ),
);

const groupTotal = (group: CheckoutSellerGroup): number =>
    group.subtotal_ngwee + (needsAddress(group) ? group.delivery_fee_ngwee : 0);

const total = computed(() =>
    props.checkout.groups.reduce((sum, group) => sum + groupTotal(group), 0),
);

const deliveryTotal = computed(() =>
    props.checkout.groups.reduce(
        (sum, group) =>
            sum + (needsAddress(group) ? group.delivery_fee_ngwee : 0),
        0,
    ),
);

const canPlace = computed(
    () =>
        outstanding.value.length === 0 &&
        missingAddress.value.length === 0 &&
        !props.checkout.blocks_checkout,
);

const submit = (): void => {
    form.payment_method = paymentMethod.value;
    form.selections = props.checkout.groups.map((group) => ({
        seller_id: group.seller.id,
        fulfilment_method: selections[group.seller.id].fulfilment_method,
        user_address_id: needsAddress(group)
            ? selections[group.seller.id].user_address_id
            : null,
        delivery_instructions:
            selections[group.seller.id].delivery_instructions || null,
        accepted: hasAccepted(group),
        accepted_policies: selections[group.seller.id].accepted_policies,
    }));

    form.post(checkoutRoutes.store().url);
};
</script>

<template>
    <Head title="Checkout" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Checkout</h1>
            <p class="text-muted-foreground text-sm">
                {{ checkout.seller_count }}
                {{ checkout.seller_count === 1 ? 'seller' : 'sellers' }}, one
                payment. Each seller sends or hands over separately.
            </p>
        </header>

        <Alert v-if="form.errors.checkout" variant="destructive">
            <AlertTriangle class="size-4" aria-hidden="true" />
            <AlertTitle>We could not place your order</AlertTitle>
            <AlertDescription>{{ form.errors.checkout }}</AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card v-for="group in checkout.groups" :key="group.seller.id">
                    <CardHeader class="gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold">
                                {{ group.seller.business_name }}
                            </h2>
                            <BadgeCheck
                                v-if="group.seller.verified"
                                class="size-4 text-emerald-600 dark:text-emerald-400"
                                aria-label="Verified seller"
                            />
                        </div>
                        <p class="text-muted-foreground text-sm">
                            {{ group.lines.length }}
                            {{ group.lines.length === 1 ? 'item' : 'items' }} ·
                            <Money :amount="group.subtotal_ngwee" />
                        </p>
                    </CardHeader>

                    <CardContent class="space-y-5">
                        <ul class="space-y-2 text-sm">
                            <li
                                v-for="line in group.lines"
                                :key="line.id"
                                class="flex items-baseline justify-between gap-3"
                            >
                                <span>
                                    {{ line.product.name }}
                                    <span class="text-muted-foreground">
                                        × {{ line.quantity }}
                                    </span>
                                </span>
                                <Money :amount="line.line_total_ngwee" />
                            </li>
                        </ul>

                        <Separator />

                        <!-- How this shop's part reaches the buyer. -->
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-medium">
                                How do you want this?
                            </legend>

                            <label
                                v-for="method in group.available_methods"
                                :key="method.value"
                                class="hover:bg-accent flex cursor-pointer items-start gap-3 rounded-md border p-3"
                                :class="
                                    selections[group.seller.id]
                                        .fulfilment_method === method.value
                                        ? 'border-primary'
                                        : ''
                                "
                            >
                                <input
                                    v-model="
                                        selections[group.seller.id]
                                            .fulfilment_method
                                    "
                                    type="radio"
                                    :name="`method-${group.seller.id}`"
                                    :value="method.value"
                                    class="mt-1"
                                />
                                <span class="flex-1 text-sm">
                                    <span
                                        class="flex flex-wrap items-center gap-2 font-medium"
                                    >
                                        <Truck
                                            v-if="method.value === 'delivery'"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        <MapPin
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ method.label }}
                                        <Badge
                                            v-if="method.value === 'delivery'"
                                            variant="secondary"
                                        >
                                            <Money
                                                :amount="
                                                    group.delivery_fee_ngwee
                                                "
                                            />
                                        </Badge>
                                    </span>
                                    <span class="text-muted-foreground block">
                                        {{ method.description }}
                                    </span>
                                    <span
                                        v-if="
                                            method.value === 'delivery' &&
                                            group.seller.delivery_note
                                        "
                                        class="text-muted-foreground block italic"
                                    >
                                        {{ group.seller.delivery_note }}
                                    </span>
                                </span>
                            </label>
                        </fieldset>

                        <!-- Collection: where to go, and how to get there. -->
                        <PickupMap
                            v-if="
                                selections[group.seller.id]
                                    .fulfilment_method === 'pickup'
                            "
                            :name="group.seller.business_name"
                            :address="group.seller.address"
                            :latitude="group.seller.latitude"
                            :longitude="group.seller.longitude"
                            :api-key="mapsApiKey"
                        />

                        <!-- Delivery: which saved address, and how to find it. -->
                        <div v-else class="space-y-3">
                            <div
                                v-if="!checkout.addresses.length"
                                class="rounded-md border border-dashed p-4 text-sm"
                            >
                                <p class="text-muted-foreground">
                                    You have no saved addresses yet.
                                </p>
                                <Button
                                    as-child
                                    variant="outline"
                                    size="sm"
                                    class="mt-2"
                                >
                                    <Link :href="addresses.index().url">
                                        Add a delivery address
                                    </Link>
                                </Button>
                            </div>

                            <fieldset v-else class="space-y-2">
                                <legend class="text-sm font-medium">
                                    Deliver to
                                </legend>

                                <label
                                    v-for="address in checkout.addresses"
                                    :key="address.id"
                                    class="hover:bg-accent flex cursor-pointer items-start gap-3 rounded-md border p-3"
                                    :class="
                                        selections[group.seller.id]
                                            .user_address_id === address.id
                                            ? 'border-primary'
                                            : ''
                                    "
                                >
                                    <input
                                        v-model="
                                            selections[group.seller.id]
                                                .user_address_id
                                        "
                                        type="radio"
                                        :name="`address-${group.seller.id}`"
                                        :value="address.id"
                                        class="mt-1"
                                    />
                                    <span class="text-sm">
                                        <span class="block font-medium">
                                            {{ address.label }} —
                                            {{ address.recipient_name }}
                                        </span>
                                        <span class="text-muted-foreground">
                                            {{ address.single_line }}
                                        </span>
                                    </span>
                                </label>
                            </fieldset>

                            <div class="space-y-1">
                                <Label :for="`instructions-${group.seller.id}`">
                                    Anything the rider should know
                                </Label>
                                <textarea
                                    :id="`instructions-${group.seller.id}`"
                                    v-model="
                                        selections[group.seller.id]
                                            .delivery_instructions
                                    "
                                    rows="2"
                                    class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                                    placeholder="Blue gate opposite the filling station. Call on arrival."
                                />
                            </div>
                        </div>

                        <Separator />

                        <!-- Terms. Blocking, per shop, recorded by version. -->
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <p class="text-sm">
                                <span
                                    v-if="hasAccepted(group)"
                                    class="flex items-center gap-2 font-medium"
                                >
                                    <Check
                                        class="size-4 text-emerald-600 dark:text-emerald-400"
                                        aria-hidden="true"
                                    />
                                    You accepted this seller's terms
                                </span>
                                <span v-else class="text-muted-foreground">
                                    Read and accept this seller's terms to order
                                    from them.
                                </span>
                            </p>

                            <Button
                                type="button"
                                :variant="
                                    hasAccepted(group) ? 'ghost' : 'default'
                                "
                                size="sm"
                                :data-test="`review-terms-${group.seller.id}`"
                                @click="termsOpenFor = group.seller.id"
                            >
                                {{
                                    hasAccepted(group)
                                        ? 'Read again'
                                        : 'Read the terms'
                                }}
                            </Button>
                        </div>

                        <TermsAcceptanceDialog
                            :open="termsOpenFor === group.seller.id"
                            :group="group"
                            :platform-terms="checkout.platform_terms"
                            @update:open="
                                termsOpenFor = $event ? group.seller.id : null
                            "
                            @accept="accept(group, $event)"
                        />

                        <p
                            class="flex items-baseline justify-between text-sm font-medium"
                        >
                            <span>This seller</span>
                            <Money :amount="groupTotal(group)" />
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- One payment for the lot. -->
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <h2 class="font-semibold">Pay</h2>
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-medium">
                                How will you pay?
                            </legend>

                            <label
                                v-for="method in checkout.payment_methods"
                                :key="method.value"
                                class="hover:bg-accent flex cursor-pointer items-start gap-3 rounded-md border p-3"
                                :class="
                                    paymentMethod === method.value
                                        ? 'border-primary'
                                        : ''
                                "
                            >
                                <input
                                    v-model="paymentMethod"
                                    type="radio"
                                    name="payment_method"
                                    :value="method.value"
                                    class="mt-1"
                                />
                                <span class="text-sm">
                                    <span
                                        class="flex items-center gap-2 font-medium"
                                    >
                                        <Smartphone
                                            v-if="
                                                method.value === 'mobile_money'
                                            "
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        <CreditCard
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ method.label }}
                                    </span>
                                    <span class="text-muted-foreground">
                                        {{ method.description }}
                                    </span>
                                </span>
                            </label>
                        </fieldset>

                        <Separator />

                        <dl class="space-y-1 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Parts</dt>
                                <dd>
                                    <Money
                                        :amount="checkout.items_total_ngwee"
                                    />
                                </dd>
                            </div>
                            <div
                                v-if="deliveryTotal"
                                class="flex justify-between"
                            >
                                <dt class="text-muted-foreground">Delivery</dt>
                                <dd><Money :amount="deliveryTotal" /></dd>
                            </div>
                            <div
                                class="flex justify-between pt-1 text-base font-semibold"
                            >
                                <dt>Total</dt>
                                <dd><Money :amount="total" /></dd>
                            </div>
                        </dl>

                        <p class="text-muted-foreground text-xs">
                            Prices include VAT. Each seller is responsible for
                            VAT on the goods they sell.
                        </p>

                        <!--
                            The button says what is missing rather than sitting
                            disabled and silent.
                        -->
                        <Alert v-if="outstanding.length" variant="default">
                            <AlertTitle>Terms still to accept</AlertTitle>
                            <AlertDescription>
                                {{
                                    outstanding
                                        .map(
                                            (group) =>
                                                group.seller.business_name,
                                        )
                                        .join(', ')
                                }}
                            </AlertDescription>
                        </Alert>

                        <Alert
                            v-if="missingAddress.length"
                            variant="destructive"
                        >
                            <AlertTitle>Delivery address needed</AlertTitle>
                            <AlertDescription>
                                {{
                                    missingAddress
                                        .map(
                                            (group) =>
                                                group.seller.business_name,
                                        )
                                        .join(', ')
                                }}
                            </AlertDescription>
                        </Alert>

                        <Button
                            class="w-full"
                            data-test="place-order"
                            :disabled="!canPlace || form.processing"
                            @click="submit"
                        >
                            Place order
                        </Button>

                        <Button
                            as-child
                            variant="ghost"
                            size="sm"
                            class="w-full"
                        >
                            <Link :href="cartRoutes.index().url"
                                >Back to cart</Link
                            >
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
