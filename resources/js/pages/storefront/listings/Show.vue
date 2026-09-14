<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    ChevronRight,
    FileText,
    Flag,
    PackageCheck,
    Truck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ListingBadges from '@/components/catalog/ListingBadges.vue';
import BackInStockButton from '@/components/inventory/BackInStockButton.vue';
import StockStatus from '@/components/inventory/StockStatus.vue';
import ProductCard from '@/components/catalog/ProductCard.vue';
import RatingList from '@/components/ratings/RatingList.vue';
import RatingSummary from '@/components/ratings/RatingSummary.vue';
import Money from '@/components/Money.vue';
import AddToCartButton from '@/components/shopping/AddToCartButton.vue';
import CompareButton from '@/components/shopping/CompareButton.vue';
import ContactSellerDialog from '@/components/shopping/ContactSellerDialog.vue';
import RequestQuoteDialog from '@/components/shopping/RequestQuoteDialog.vue';
import WishlistButton from '@/components/shopping/WishlistButton.vue';
import BlurredContact from '@/components/storefront/BlurredContact.vue';
import VerificationBadge from '@/components/storefront/VerificationBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import categories from '@/routes/categories';
import sellers from '@/routes/sellers';
import type {
    BreadcrumbNode,
    PlatformMinimumRefund,
    ProductCard as ProductCardType,
    ProductDetail,
    Rating,
    RatingSummary as RatingSummaryType,
} from '@/types';

/**
 * A listing's public page.
 *
 * Server-rendered, because this is where most buyers arrive from a search
 * engine. Everything on it is public except the seller's contact block: a
 * guest gets labels and a masked shape, already masked by the server.
 */
const props = defineProps<{
    listing: ProductDetail;
    breadcrumb: BreadcrumbNode[];
    related: ProductCardType[];
    platformMinimumRefund: PlatformMinimumRefund;
    /* Reviews of the SHOP. A part sells once; a shop is what a buyer judges. */
    reviews: {
        summary: RatingSummaryType;
        reviews: {
            data: Rating[];
            links: { url: string | null; label: string; active: boolean }[];
        };
    };
    reportReasons?: { value: string; label: string }[];
}>();

const activePhoto = ref(props.listing.photos[0] ?? null);

/* The shop's name where it is known; the listing payload makes it optional. */
const reviewsHeading = computed(() =>
    props.listing.seller
        ? `What buyers said about ${props.listing.seller.business_name}`
        : 'What buyers said about this seller',
);

const selectedVariant = ref(
    props.listing.variants.find((variant) => variant.is_default) ??
        props.listing.variants[0] ??
        null,
);

/**
 * Whether this buyer is already waiting on the selected option.
 *
 * The subscribed variant ids come down with the listing, so switching options
 * does not cost a request.
 */
const page = usePage();

const isSubscribed = computed(
    () =>
        selectedVariant.value !== null &&
        props.listing.stock_alerts.includes(selectedVariant.value.id),
);

/** Reporting arrives with its own module; the affordance is here. */
</script>

<template>
    <Head :title="listing.name">
        <meta name="description" :content="listing.description.slice(0, 160)" />
    </Head>

    <article class="space-y-8">
        <nav aria-label="Breadcrumb">
            <ol
                class="text-muted-foreground flex flex-wrap items-center gap-1 text-sm"
            >
                <li
                    v-for="(node, index) in breadcrumb"
                    :key="node.id"
                    class="flex items-center gap-1"
                >
                    <ChevronRight
                        v-if="index > 0"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    <Link
                        :href="categories.show(node.slug).url"
                        class="hover:text-foreground underline-offset-4 hover:underline"
                    >
                        {{ node.name }}
                    </Link>
                </li>
            </ol>
        </nav>

        <div class="grid gap-8 lg:grid-cols-2">
            <section class="space-y-3">
                <div
                    class="bg-muted aspect-4/3 w-full overflow-hidden rounded-lg border"
                >
                    <img
                        v-if="activePhoto"
                        :src="activePhoto.web"
                        :alt="activePhoto.alt"
                        class="size-full object-contain"
                    />
                    <div
                        v-else
                        class="text-muted-foreground flex size-full items-center justify-center text-sm"
                    >
                        No photos yet
                    </div>
                </div>

                <div
                    v-if="listing.photos.length > 1"
                    class="flex flex-wrap gap-2"
                >
                    <button
                        v-for="photo in listing.photos"
                        :key="photo.id"
                        type="button"
                        class="focus-visible:ring-ring size-16 overflow-hidden rounded border focus-visible:ring-2 focus-visible:outline-none"
                        :class="{
                            'ring-primary ring-2': activePhoto?.id === photo.id,
                        }"
                        @click="activePhoto = photo"
                    >
                        <img
                            :src="photo.thumb"
                            :alt="photo.alt"
                            loading="lazy"
                            class="size-full object-cover"
                        />
                    </button>
                </div>

                <video
                    v-if="listing.video?.source"
                    :src="listing.video.source"
                    :poster="listing.video.poster ?? undefined"
                    controls
                    preload="none"
                    class="w-full rounded-lg border"
                >
                    Your browser cannot play this video.
                </video>
            </section>

            <section class="space-y-5">
                <!-- Both badges, always, through the one component that renders the pair. -->
                <ListingBadges
                    :condition="listing.condition"
                    :inspection="listing.inspection"
                />

                <div class="space-y-1">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ listing.name }}
                    </h1>
                    <p v-if="listing.fitment" class="text-muted-foreground">
                        Fits {{ listing.fitment }}
                    </p>
                </div>

                <div class="space-y-2">
                    <p class="text-3xl font-semibold">
                        <Money
                            v-if="selectedVariant"
                            :amount="selectedVariant.price_ngwee"
                        />
                        <span v-else class="text-muted-foreground text-lg">
                            Price on request
                        </span>
                    </p>
                    <p class="text-muted-foreground text-xs">
                        Price includes VAT.
                    </p>
                </div>

                <div v-if="listing.variants.length > 1" class="space-y-2">
                    <p class="text-sm font-medium">Options</p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="variant in listing.variants"
                            :key="variant.id"
                            type="button"
                            size="sm"
                            :variant="
                                selectedVariant?.id === variant.id
                                    ? 'default'
                                    : 'outline'
                            "
                            :disabled="!variant.in_stock"
                            @click="selectedVariant = variant"
                        >
                            {{ variant.name ?? 'Standard' }}
                        </Button>
                    </div>
                </div>

                <div class="space-y-2">
                    <!--
                        Availability and confidence together. The seller's own
                        quantity is not shown: "2 left" from a shop whose
                        counter sold one this morning is a more confident
                        claim than the platform can honestly make.
                    -->
                    <StockStatus
                        v-if="selectedVariant"
                        :stock="selectedVariant.level"
                        :freshness="listing.freshness"
                    />

                    <p
                        v-if="listing.freshness.label"
                        class="text-muted-foreground text-sm"
                    >
                        {{ listing.freshness.description }}
                    </p>

                    <div
                        class="text-muted-foreground flex flex-wrap items-center gap-4 text-sm"
                    >
                        <span
                            v-if="listing.freshness.confirmed_days_ago <= 1"
                            class="inline-flex items-center gap-1.5"
                        >
                            <PackageCheck class="size-4" aria-hidden="true" />
                            Stock confirmed
                            {{
                                listing.freshness.confirmed_days_ago === 0
                                    ? 'today'
                                    : 'yesterday'
                            }}
                        </span>
                        <span
                            v-if="listing.delivery_available"
                            class="inline-flex items-center gap-1.5"
                        >
                            <Truck class="size-4" aria-hidden="true" />
                            Delivery available
                        </span>
                    </div>
                </div>

                <AddToCartButton
                    v-if="selectedVariant"
                    :variant="selectedVariant"
                />

                <div class="flex flex-wrap gap-2">
                    <!-- Only where there is something to wait for. -->
                    <BackInStockButton
                        v-if="
                            selectedVariant && !selectedVariant.level.available
                        "
                        :variant="selectedVariant"
                        :subscribed="isSubscribed"
                        :is-authenticated="page.props.auth.user !== null"
                    />

                    <WishlistButton :listing="listing" variant="labelled" />

                    <!--
                        Buying several is a conversation on this market, not a
                        bigger number in a box: the seller answers with a price
                        and a date it stands until, and accepting puts that
                        price in the cart rather than the shelf price.
                    -->
                    <RequestQuoteDialog
                        v-if="selectedVariant"
                        :variant="selectedVariant"
                        :is-authenticated="page.props.auth.user !== null"
                    />

                    <CompareButton :listing="listing" variant="labelled" />

                    <Button type="button" variant="ghost" size="sm" disabled>
                        <Flag class="size-4" aria-hidden="true" />
                        Report listing
                    </Button>
                </div>
            </section>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-base">About this part</CardTitle>
                </CardHeader>
                <CardContent class="space-y-6">
                    <p class="whitespace-pre-line">{{ listing.description }}</p>

                    <div v-if="listing.specification.length" class="space-y-2">
                        <h2 class="text-sm font-semibold">Specification</h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr
                                        v-for="row in listing.specification"
                                        :key="row.label"
                                        class="border-b last:border-0"
                                    >
                                        <th
                                            scope="row"
                                            class="text-muted-foreground py-2 pr-4 text-left font-normal"
                                        >
                                            {{ row.label }}
                                        </th>
                                        <td class="py-2">{{ row.value }}</td>
                                    </tr>
                                    <tr class="border-b last:border-0">
                                        <th
                                            scope="row"
                                            class="text-muted-foreground py-2 pr-4 text-left font-normal"
                                        >
                                            Part source
                                        </th>
                                        <td class="py-2">
                                            {{ listing.sourcing.label }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="listing.warranty_text" class="space-y-1">
                        <h2 class="text-sm font-semibold">Warranty</h2>
                        <p class="text-sm">{{ listing.warranty_text }}</p>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="listing.seller">
                <CardHeader>
                    <CardTitle class="text-base">Sold by</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="space-y-2">
                        <Link
                            :href="sellers.show(listing.seller.slug).url"
                            class="font-medium underline-offset-4 hover:underline"
                        >
                            {{ listing.seller.business_name }}
                        </Link>

                        <div class="flex flex-wrap items-center gap-2">
                            <VerificationBadge
                                :verified="listing.seller.verified"
                                :label="listing.seller.verification_label"
                            />
                            <Badge variant="secondary">
                                {{ listing.seller.type_label }}
                            </Badge>
                        </div>

                        <p
                            v-if="listing.seller.location"
                            class="text-muted-foreground text-sm"
                        >
                            {{ listing.seller.location }}
                        </p>
                    </div>

                    <!-- Values are masked on the server for a guest; there is nothing real here to unblur. -->
                    <BlurredContact
                        :contact="listing.seller.contact"
                        :business-name="listing.seller.business_name"
                    />

                    <!--
                        Beside the phone number, not instead of it. A buyer who
                        wants to ring the shop should ring the shop; this is for
                        the ones who would rather write, and it leaves a record
                        both sides can point at.
                    -->
                    <ContactSellerDialog
                        :seller-slug="listing.seller.slug"
                        :seller-name="listing.seller.business_name"
                        :product-id="listing.id"
                        :is-authenticated="page.props.auth.user !== null"
                    />
                </CardContent>
            </Card>
        </div>

        <section
            v-if="listing.seller?.policies.length"
            class="space-y-2"
            aria-labelledby="policies-heading"
        >
            <h2
                id="policies-heading"
                class="text-lg font-semibold tracking-tight"
            >
                Policies you agree to when you order
            </h2>

            <Collapsible
                v-for="policy in listing.seller.policies"
                :key="policy.id"
                class="rounded-lg border"
            >
                <CollapsibleTrigger
                    class="hover:bg-muted/50 flex w-full items-center justify-between gap-2 p-4 text-left text-sm font-medium"
                >
                    <span class="inline-flex items-center gap-2">
                        <FileText class="size-4" aria-hidden="true" />
                        {{ policy.type_label }}
                    </span>
                    <Badge variant="outline">
                        Version {{ policy.version }}
                    </Badge>
                </CollapsibleTrigger>
                <CollapsibleContent class="space-y-3 px-4 pb-4 text-sm">
                    <div class="whitespace-pre-line">{{ policy.body }}</div>

                    <p
                        v-if="policy.shows_platform_minimum"
                        class="bg-muted rounded-md p-3 text-xs"
                    >
                        <BadgeCheck
                            class="mr-1 inline size-3.5"
                            aria-hidden="true"
                        />
                        {{ platformMinimumRefund.statement }}
                    </p>
                </CollapsibleContent>
            </Collapsible>
        </section>

        <section class="space-y-4" aria-labelledby="listing-reviews">
            <h2 id="listing-reviews" class="sr-only">Reviews of this seller</h2>
            <RatingSummary
                :summary="reviews.summary"
                :heading="reviewsHeading"
            />
            <RatingList
                :reviews="reviews.reviews"
                :report-reasons="reportReasons"
            />
        </section>

        <section v-if="related.length" class="space-y-3">
            <h2 class="text-lg font-semibold tracking-tight">
                More in this category
            </h2>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <ProductCard
                    v-for="item in related"
                    :key="item.id"
                    :listing="item"
                />
            </div>
        </section>
    </article>
</template>
