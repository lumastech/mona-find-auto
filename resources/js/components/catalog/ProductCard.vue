<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BadgeCheck, ImageOff, Truck } from '@lucide/vue';
import ListingBadges from '@/components/catalog/ListingBadges.vue';
import StockStatus from '@/components/inventory/StockStatus.vue';
import Money from '@/components/Money.vue';
import CompareButton from '@/components/shopping/CompareButton.vue';
import WishlistButton from '@/components/shopping/WishlistButton.vue';
import { Card, CardContent } from '@/components/ui/card';
import listings from '@/routes/listings';
import type { ProductCard } from '@/types';

/**
 * A listing in a grid.
 *
 * Both badges are always here, through ListingBadges — the requirement is
 * that a buyer can read the condition and MonaFind's inspection position off
 * every card without opening it. StockStatus sits beneath them and carries
 * the other pair a buyer needs before travelling: whether the part is there,
 * and how recently the seller said so.
 *
 * The price is an integer number of ngwee and stays one until <Money />
 * formats it.
 *
 * The heart sits over the photograph rather than under the price, because it
 * has to be reachable with a thumb on a phone without opening the listing —
 * and it is a real form post, so a guest pressing it is sent to log in and
 * brought back here. Both it and the compare toggle stop the click from
 * reaching the Link they sit inside.
 */
defineProps<{ listing: ProductCard }>();
</script>

<template>
    <Card class="overflow-hidden py-0 transition-shadow hover:shadow-md">
        <Link
            :href="listings.show(listing.slug).url"
            class="focus-visible:ring-ring block focus-visible:ring-2 focus-visible:outline-none"
        >
            <div class="bg-muted relative aspect-4/3 w-full">
                <div class="absolute top-2 right-2 z-10 flex flex-col gap-1.5">
                    <WishlistButton :listing="listing" />
                    <CompareButton :listing="listing" />
                </div>

                <img
                    v-if="listing.thumbnail_url"
                    :src="listing.thumbnail_url"
                    :alt="listing.name"
                    loading="lazy"
                    class="size-full object-cover"
                />
                <div
                    v-else
                    class="text-muted-foreground flex size-full items-center justify-center"
                >
                    <ImageOff class="size-8" aria-hidden="true" />
                    <span class="sr-only">No photo yet</span>
                </div>
            </div>

            <CardContent class="space-y-2 p-3">
                <ListingBadges
                    :condition="listing.condition"
                    :inspection="listing.inspection"
                    compact
                />

                <!--
                    Availability and confidence. "In stock" from a shop that
                    has confirmed nothing in a week is a weaker claim than
                    "Low stock" from one that confirmed this morning, so the
                    card never shows one without the other.
                -->
                <StockStatus
                    :stock="listing.stock"
                    :freshness="listing.freshness"
                    compact
                />

                <h3 class="line-clamp-2 text-sm font-medium">
                    {{ listing.name }}
                </h3>

                <p
                    v-if="listing.fitment"
                    class="text-muted-foreground line-clamp-1 text-xs"
                >
                    {{ listing.fitment }}
                </p>

                <p class="text-base font-semibold">
                    <span
                        v-if="listing.has_multiple_variants"
                        class="text-muted-foreground mr-1 text-xs font-normal"
                    >
                        from
                    </span>
                    <Money
                        v-if="listing.price_ngwee !== null"
                        :amount="listing.price_ngwee"
                    />
                    <span v-else class="text-muted-foreground text-sm">
                        Price on request
                    </span>
                </p>

                <div
                    class="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"
                >
                    <span
                        v-if="listing.seller"
                        class="inline-flex items-center gap-1"
                    >
                        <BadgeCheck
                            v-if="listing.seller.verified"
                            class="text-primary size-3.5"
                            aria-hidden="true"
                        />
                        {{ listing.seller.business_name }}
                    </span>
                    <span v-if="listing.seller?.city">
                        · {{ listing.seller.city }}
                    </span>
                    <span
                        v-if="listing.delivery_available"
                        class="inline-flex items-center gap-1"
                    >
                        <Truck class="size-3.5" aria-hidden="true" />
                        Delivery
                    </span>
                </div>
            </CardContent>
        </Link>
    </Card>
</template>
