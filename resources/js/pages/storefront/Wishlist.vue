<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    Heart,
    ImageOff,
    PackageX,
    RotateCcw,
    ShoppingCart,
    Trash2,
} from '@lucide/vue';
import ListingBadges from '@/components/catalog/ListingBadges.vue';
import StockStatus from '@/components/inventory/StockStatus.vue';
import Money from '@/components/Money.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import listings from '@/routes/listings';
import { home } from '@/routes';
import wishlist from '@/routes/wishlist';
import type { WishlistItem } from '@/types';

/**
 * The wishlist, which is really a page about what changed.
 *
 * A plain list of saved parts is a bookmark folder. What brings a buyer back
 * is MonaFind being able to say that the turbo they saved in March is K450
 * cheaper, or that the last one went — so the change indicator sits above the
 * name on every row that has one, and nothing at all is shown on the rows
 * that do not.
 */
defineProps<{ items: WishlistItem[] }>();

const remove = (slug: string): void => {
    router.delete(wishlist.destroy(slug).url, { preserveScroll: true });
};

const moveToCart = (slug: string): void => {
    router.post(wishlist.moveToCart(slug).url, {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Wishlist" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Your wishlist</h1>
            <p class="text-muted-foreground text-sm">
                {{ items.length }}
                {{ items.length === 1 ? 'part' : 'parts' }} saved. We watch the
                price and the shelf for you.
            </p>
        </header>

        <!-- Nothing saved yet. -->
        <Card v-if="items.length === 0">
            <CardContent class="flex flex-col items-center gap-3 py-12">
                <Heart
                    class="text-muted-foreground size-8"
                    aria-hidden="true"
                />
                <p class="text-muted-foreground text-sm">
                    Nothing saved yet. Tap the heart on any part to keep an eye
                    on it.
                </p>
                <Button as-child>
                    <Link :href="home()">Browse parts</Link>
                </Button>
            </CardContent>
        </Card>

        <ul v-else class="space-y-4">
            <li v-for="item in items" :key="item.id">
                <Card>
                    <CardContent class="flex flex-col gap-4 p-4 sm:flex-row">
                        <Link
                            :href="listings.show(item.listing.slug).url"
                            class="bg-muted aspect-4/3 w-full shrink-0 overflow-hidden rounded-md sm:w-40"
                        >
                            <img
                                v-if="item.listing.thumbnail_url"
                                :src="item.listing.thumbnail_url"
                                :alt="item.listing.name"
                                loading="lazy"
                                class="size-full object-cover"
                            />
                            <div
                                v-else
                                class="text-muted-foreground flex size-full items-center justify-center"
                            >
                                <ImageOff class="size-6" aria-hidden="true" />
                            </div>
                        </Link>

                        <div class="min-w-0 flex-1 space-y-2">
                            <!--
                                The news, first. Only rendered where there is
                                any: a "nothing changed" line on every row
                                would make the rows that matter invisible.
                            -->
                            <p
                                v-if="item.change.price_dropped"
                                class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600 dark:text-emerald-400"
                            >
                                <ArrowDownRight
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Price dropped by
                                <Money
                                    :amount="
                                        Math.abs(
                                            item.change.difference_ngwee ?? 0,
                                        )
                                    "
                                />
                                since you saved it
                            </p>

                            <p
                                v-else-if="item.change.price_rose"
                                class="text-muted-foreground inline-flex items-center gap-1.5 text-sm"
                            >
                                <ArrowUpRight
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Up
                                <Money
                                    :amount="item.change.difference_ngwee ?? 0"
                                />
                                since you saved it
                            </p>

                            <p
                                v-if="item.change.went_out_of_stock"
                                class="text-destructive inline-flex items-center gap-1.5 text-sm font-medium"
                            >
                                <PackageX class="size-4" aria-hidden="true" />
                                This sold out since you saved it
                            </p>

                            <p
                                v-else-if="item.change.came_back_in_stock"
                                class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600 dark:text-emerald-400"
                            >
                                <RotateCcw class="size-4" aria-hidden="true" />
                                Back in stock
                            </p>

                            <ListingBadges
                                :condition="item.listing.condition"
                                :inspection="item.listing.inspection"
                                compact
                            />

                            <h2 class="font-medium">
                                <Link
                                    :href="listings.show(item.listing.slug).url"
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ item.listing.name }}
                                </Link>
                            </h2>

                            <StockStatus
                                :stock="item.listing.stock"
                                :freshness="item.listing.freshness"
                                compact
                            />

                            <p class="text-lg font-semibold">
                                <Money
                                    v-if="item.listing.price_ngwee !== null"
                                    :amount="item.listing.price_ngwee"
                                />
                                <span
                                    v-else
                                    class="text-muted-foreground text-sm"
                                >
                                    Price on request
                                </span>
                                <span
                                    v-if="
                                        item.change.price_dropped &&
                                        item.change.saved_price_ngwee !== null
                                    "
                                    class="text-muted-foreground ml-2 text-sm font-normal line-through"
                                >
                                    <Money
                                        :amount="item.change.saved_price_ngwee"
                                    />
                                </span>
                            </p>

                            <p
                                v-if="item.listing.seller"
                                class="text-muted-foreground text-xs"
                            >
                                {{ item.listing.seller.business_name }}
                                <span v-if="item.listing.seller.city">
                                    · {{ item.listing.seller.city }}
                                </span>
                            </p>
                        </div>

                        <div
                            class="flex shrink-0 flex-row gap-2 sm:flex-col sm:justify-center"
                        >
                            <Button
                                type="button"
                                :disabled="!item.purchasable"
                                :title="
                                    item.purchasable
                                        ? undefined
                                        : 'This is not available to buy right now.'
                                "
                                @click="moveToCart(item.listing.slug)"
                            >
                                <ShoppingCart
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Move to cart
                            </Button>

                            <Button
                                type="button"
                                variant="ghost"
                                :aria-label="`Remove ${item.listing.name} from your wishlist`"
                                @click="remove(item.listing.slug)"
                            >
                                <Trash2 class="size-4" aria-hidden="true" />
                                Remove
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </li>
        </ul>
    </div>
</template>
