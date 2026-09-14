<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BadgeCheck,
    ImageOff,
    Info,
    Minus,
    Plus,
    ShoppingCart,
    Trash2,
    Truck,
} from '@lucide/vue';
import ListingBadges from '@/components/catalog/ListingBadges.vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import cartRoutes from '@/routes/cart';
import { home } from '@/routes';
import listings from '@/routes/listings';
import sellers from '@/routes/sellers';
import type { Cart, CartLine } from '@/types';

/**
 * The cart, grouped by shop.
 *
 * The grouping is not a layout preference. A buyer fixing one car buys the
 * filter from a parts shop and the wing mirror from a breaker across town,
 * and those are two transactions with two dispatches under two sets of terms
 * — a single combined total would be describing a delivery nobody is going to
 * make. So every shop gets its own panel, its own subtotal, and a line saying
 * it ships separately.
 *
 * The notices at the top are the other half. The server re-checked every line
 * against its listing while building this page, and anything that moved is
 * said out loud rather than quietly applied.
 */
defineProps<{ cart: Cart }>();

const setQuantity = (line: CartLine, quantity: number): void => {
    router.patch(
        cartRoutes.update(line.id).url,
        { quantity },
        { preserveScroll: true },
    );
};

const remove = (line: CartLine): void => {
    router.delete(cartRoutes.destroy(line.id).url, { preserveScroll: true });
};
</script>

<template>
    <Head title="Cart" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Your cart</h1>
            <p v-if="!cart.is_empty" class="text-muted-foreground text-sm">
                {{ cart.unit_count }}
                {{ cart.unit_count === 1 ? 'part' : 'parts' }} from
                {{ cart.seller_count }}
                {{ cart.seller_count === 1 ? 'seller' : 'sellers' }}.
            </p>
        </header>

        <!--
            What changed since the buyer last looked. Shown before the lines,
            because a price that moved is the first thing they need to know.
        -->
        <Alert
            v-for="issue in cart.issues"
            :key="issue.value"
            :variant="issue.blocks_checkout ? 'destructive' : 'default'"
        >
            <component
                :is="issue.blocks_checkout ? AlertTriangle : Info"
                class="size-4"
                aria-hidden="true"
            />
            <AlertTitle>{{ issue.label }}</AlertTitle>
            <AlertDescription>{{ issue.description }}</AlertDescription>
        </Alert>

        <Alert v-if="cart.removed.length" variant="destructive">
            <AlertTriangle class="size-4" aria-hidden="true" />
            <AlertTitle>Removed from your cart</AlertTitle>
            <AlertDescription>
                <ul class="list-inside list-disc">
                    <li v-for="line in cart.removed" :key="line.product_id">
                        {{ line.name }} from {{ line.seller }} —
                        {{ line.reason_label.toLowerCase() }}
                    </li>
                </ul>
            </AlertDescription>
        </Alert>

        <Card v-if="cart.is_empty">
            <CardContent class="flex flex-col items-center gap-3 py-12">
                <ShoppingCart
                    class="text-muted-foreground size-8"
                    aria-hidden="true"
                />
                <p class="text-muted-foreground text-sm">Your cart is empty.</p>
                <Button as-child>
                    <Link :href="home()">Browse parts</Link>
                </Button>
            </CardContent>
        </Card>

        <div v-else class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card v-for="group in cart.groups" :key="group.seller.id">
                    <CardHeader class="gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="sellers.show(group.seller.slug).url"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ group.seller.business_name }}
                            </Link>
                            <BadgeCheck
                                v-if="group.seller.verified"
                                class="text-primary size-4"
                                aria-hidden="true"
                            />
                            <span
                                v-if="group.seller.city"
                                class="text-muted-foreground text-sm"
                            >
                                · {{ group.seller.city }}
                            </span>
                        </div>
                        <!--
                            Said on every group, every time. A buyer who reads
                            one total and expects one delivery is a buyer about
                            to be surprised twice.
                        -->
                        <p
                            class="text-muted-foreground inline-flex items-center gap-1.5 text-xs"
                        >
                            <Truck class="size-3.5" aria-hidden="true" />
                            Each seller dispatches separately.
                        </p>
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <div
                            v-for="line in group.lines"
                            :key="line.id"
                            class="flex gap-3"
                        >
                            <Link
                                :href="listings.show(line.product.slug).url"
                                class="bg-muted aspect-square size-20 shrink-0 overflow-hidden rounded-md"
                            >
                                <img
                                    v-if="line.product.thumbnail_url"
                                    :src="line.product.thumbnail_url"
                                    :alt="line.product.name"
                                    loading="lazy"
                                    class="size-full object-cover"
                                />
                                <div
                                    v-else
                                    class="text-muted-foreground flex size-full items-center justify-center"
                                >
                                    <ImageOff
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                            </Link>

                            <div class="min-w-0 flex-1 space-y-1.5">
                                <ListingBadges
                                    :condition="line.product.condition"
                                    :inspection="line.product.inspection"
                                    compact
                                />

                                <Link
                                    :href="listings.show(line.product.slug).url"
                                    class="line-clamp-2 block text-sm font-medium underline-offset-4 hover:underline"
                                >
                                    {{ line.product.name }}
                                </Link>

                                <p
                                    v-if="line.variant.name"
                                    class="text-muted-foreground text-xs"
                                >
                                    {{ line.variant.name }}
                                </p>

                                <!-- Anything the re-check found on this line. -->
                                <div
                                    v-if="line.issues.length"
                                    class="flex flex-wrap gap-1"
                                >
                                    <Badge
                                        v-for="issue in line.issues"
                                        :key="issue.value"
                                        :variant="issue.variant"
                                        :title="issue.description"
                                    >
                                        {{ issue.label }}
                                    </Badge>
                                </div>

                                <p
                                    v-if="line.quotation"
                                    class="text-muted-foreground text-xs"
                                >
                                    Quoted price
                                    <template v-if="line.quotation.valid_until">
                                        · valid until
                                        {{ line.quotation.valid_until }}
                                    </template>
                                </p>

                                <div
                                    class="flex flex-wrap items-center justify-between gap-2 pt-1"
                                >
                                    <div
                                        class="flex items-center rounded-md border"
                                        role="group"
                                        aria-label="Quantity"
                                    >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-8 rounded-r-none"
                                            aria-label="One fewer"
                                            @click="
                                                setQuantity(
                                                    line,
                                                    line.quantity - 1,
                                                )
                                            "
                                        >
                                            <Minus
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                        <span
                                            class="w-9 text-center text-sm tabular-nums"
                                        >
                                            {{ line.quantity }}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-8 rounded-l-none"
                                            :disabled="
                                                line.quantity >=
                                                line.variant.max_quantity
                                            "
                                            aria-label="One more"
                                            @click="
                                                setQuantity(
                                                    line,
                                                    line.quantity + 1,
                                                )
                                            "
                                        >
                                            <Plus
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </div>

                                    <div class="text-right">
                                        <p class="font-semibold">
                                            <Money
                                                :amount="line.line_total_ngwee"
                                            />
                                        </p>
                                        <!-- What they last saw, when it is not what they will pay. -->
                                        <p
                                            v-if="
                                                line.previous_unit_price_ngwee !==
                                                null
                                            "
                                            class="text-muted-foreground text-xs"
                                        >
                                            was
                                            <Money
                                                :amount="
                                                    line.previous_unit_price_ngwee
                                                "
                                            />
                                            each
                                        </p>
                                    </div>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Remove ${line.product.name}`"
                                        @click="remove(line)"
                                    >
                                        <Trash2
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <Separator />

                        <div class="flex items-center justify-between text-sm">
                            <span class="text-muted-foreground">
                                Subtotal from {{ group.seller.business_name }}
                            </span>
                            <span class="font-semibold">
                                <Money :amount="group.subtotal_ngwee" />
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card class="h-fit lg:sticky lg:top-20">
                <CardContent class="space-y-4 pt-6">
                    <h2 class="font-semibold">Summary</h2>

                    <div
                        v-for="group in cart.groups"
                        :key="group.seller.id"
                        class="flex justify-between gap-2 text-sm"
                    >
                        <span class="text-muted-foreground truncate">
                            {{ group.seller.business_name }}
                        </span>
                        <Money :amount="group.subtotal_ngwee" />
                    </div>

                    <Separator />

                    <div class="flex justify-between font-semibold">
                        <span>Total</span>
                        <Money :amount="cart.total_ngwee" />
                    </div>

                    <p class="text-muted-foreground text-xs">
                        Prices include VAT. Delivery is arranged with each
                        seller separately.
                    </p>

                    <Button
                        class="w-full"
                        :disabled="cart.blocks_checkout"
                        :title="
                            cart.blocks_checkout
                                ? 'Sort out the flagged lines before checking out.'
                                : undefined
                        "
                    >
                        Checkout
                    </Button>

                    <p
                        v-if="cart.blocks_checkout"
                        class="text-destructive text-xs"
                    >
                        Remove or reduce the flagged lines to continue.
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
