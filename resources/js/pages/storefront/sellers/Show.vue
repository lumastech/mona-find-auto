<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Clock, MapPin, PackageSearch, ShieldCheck, Star } from '@lucide/vue';
import { computed } from 'vue';
import RatingList from '@/components/ratings/RatingList.vue';
import RatingSummary from '@/components/ratings/RatingSummary.vue';
import BlurredContact from '@/components/storefront/BlurredContact.vue';
import VerificationBadge from '@/components/storefront/VerificationBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    PlatformMinimumRefund,
    Rating,
    RatingSummary as RatingSummaryType,
    SellerProfile,
} from '@/types';

/**
 * A seller's public page.
 *
 * Server-rendered so search engines index it, which is where most buyers
 * arrive from. Everything on it is public except the contact block, which a
 * guest sees as labels and a blur.
 */
const props = defineProps<{
    seller: SellerProfile;
    platformMinimumRefund: PlatformMinimumRefund;
    mapsApiKey?: string | null;
    reviews: {
        summary: RatingSummaryType;
        reviews: {
            data: Rating[];
            links: { url: string | null; label: string; active: boolean }[];
        };
    };
    reportReasons?: { value: string; label: string }[];
}>();

const weekdays: { key: string; label: string }[] = [
    { key: 'mon', label: 'Monday' },
    { key: 'tue', label: 'Tuesday' },
    { key: 'wed', label: 'Wednesday' },
    { key: 'thu', label: 'Thursday' },
    { key: 'fri', label: 'Friday' },
    { key: 'sat', label: 'Saturday' },
    { key: 'sun', label: 'Sunday' },
];

/**
 * A static map needs no SDK and no JavaScript, which matters on the phones
 * most buyers browse from. Without a key the address alone has to do.
 */
const mapUrl = computed(() => {
    const { latitude, longitude } = props.seller.location;

    if (!props.mapsApiKey || latitude === null || longitude === null) {
        return null;
    }

    return `https://maps.googleapis.com/maps/api/staticmap?center=${latitude},${longitude}&zoom=15&size=640x300&scale=2&markers=color:red%7C${latitude},${longitude}&key=${props.mapsApiKey}`;
});

const directionsUrl = computed(() => {
    const { latitude, longitude } = props.seller.location;

    return latitude === null || longitude === null
        ? null
        : `https://www.google.com/maps/dir/?api=1&destination=${latitude},${longitude}`;
});
</script>

<template>
    <Head :title="seller.business_name">
        <meta
            name="description"
            :content="
                seller.description ??
                `${seller.type_label} in ${seller.location.city ?? 'Zambia'} on MonaFindAuto.`
            "
        />
    </Head>

    <article class="space-y-8">
        <header class="flex flex-wrap items-start gap-4">
            <img
                v-if="seller.logo_url"
                :src="seller.logo_url"
                :alt="`${seller.business_name} logo`"
                class="size-16 shrink-0 rounded-lg border object-cover"
            />

            <div class="min-w-0 flex-1 space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ seller.business_name }}
                </h1>

                <div class="flex flex-wrap items-center gap-2">
                    <VerificationBadge
                        :verified="seller.verified"
                        :label="seller.verification_label"
                    />
                    <Badge variant="secondary">{{ seller.type_label }}</Badge>
                    <Badge v-if="seller.sells_breaker_stock" variant="outline">
                        Car Breaker stock
                    </Badge>
                </div>

                <p
                    class="text-muted-foreground flex items-center gap-1.5 text-sm"
                >
                    <Star class="size-4" aria-hidden="true" />
                    <span v-if="seller.rating.count">
                        {{ seller.rating.average }} from
                        {{ seller.rating.count }} reviews
                    </span>
                    <span v-else>No reviews yet</span>
                </p>
            </div>
        </header>

        <p v-if="seller.description" class="max-w-2xl">
            {{ seller.description }}
        </p>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <MapPin class="size-4" aria-hidden="true" />
                        Where to find them
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <p>{{ seller.location.single_line }}</p>

                    <img
                        v-if="mapUrl"
                        :src="mapUrl"
                        :alt="`Map showing ${seller.business_name}`"
                        loading="lazy"
                        class="w-full rounded-lg border"
                    />

                    <a
                        v-if="directionsUrl"
                        :href="directionsUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm underline-offset-4 hover:underline"
                    >
                        Get directions
                    </a>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Contact</CardTitle>
                </CardHeader>
                <CardContent>
                    <BlurredContact
                        :contact="seller.contact"
                        :business-name="seller.business_name"
                    />
                </CardContent>
            </Card>

            <Card v-if="seller.opening_hours">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <Clock class="size-4" aria-hidden="true" />
                        Opening hours
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="space-y-1 text-sm">
                        <div
                            v-for="day in weekdays"
                            :key="day.key"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">
                                {{ day.label }}
                            </dt>
                            <dd v-if="seller.opening_hours[day.key]">
                                {{ seller.opening_hours[day.key].open }} –
                                {{ seller.opening_hours[day.key].close }}
                            </dd>
                            <dd v-else class="text-muted-foreground">Closed</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>
        </div>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold tracking-tight">
                Policies you agree to when you order
            </h2>

            <Card v-for="policy in seller.policies ?? []" :key="policy.id">
                <CardHeader>
                    <CardTitle
                        class="flex flex-wrap items-center gap-2 text-base"
                    >
                        {{ policy.type_label }}
                        <Badge variant="outline">
                            Version {{ policy.version }}
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-4 text-sm">
                    <div class="whitespace-pre-line">{{ policy.excerpt }}</div>

                    <div
                        v-if="policy.shows_platform_minimum"
                        class="bg-muted/50 flex gap-3 rounded-lg border p-3"
                    >
                        <ShieldCheck
                            class="text-primary mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p class="text-muted-foreground">
                            <span class="text-foreground font-medium">
                                MonaFind minimum:
                            </span>
                            {{ platformMinimumRefund.statement }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <p
                v-if="!(seller.policies ?? []).length"
                class="text-muted-foreground text-sm"
            >
                This seller has not published their policies yet.
            </p>
        </section>

        <section class="space-y-4" aria-labelledby="seller-reviews">
            <h2 id="seller-reviews" class="sr-only">Reviews</h2>
            <RatingSummary :summary="reviews.summary" />
            <RatingList
                :reviews="reviews.reviews"
                :report-reasons="reportReasons"
            />
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold tracking-tight">Their listings</h2>
            <div
                class="text-muted-foreground flex flex-col items-center gap-2 rounded-lg border border-dashed p-10 text-sm"
            >
                <PackageSearch class="size-6" aria-hidden="true" />
                <p>Listings from this seller appear here.</p>
            </div>
        </section>
    </article>
</template>
