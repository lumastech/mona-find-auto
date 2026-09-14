<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarClock, FileText, ImageOff, Truck } from '@lucide/vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import listings from '@/routes/listings';
import { home } from '@/routes';
import quotationRoutes from '@/routes/quotations';
import sellers from '@/routes/sellers';
import type { Quotation } from '@/types';

/**
 * The buyer's quote requests, and what came back.
 *
 * Accepting is the only action here, and it is offered strictly on
 * `is_acceptable` rather than on the status badge. A quote can read "Quoted"
 * and already be dead — expiry is the passage of time and the nightly sweep
 * only writes it down afterwards — so a page that trusted the status would
 * offer a button the server is about to refuse.
 */
defineProps<{
    quotations: {
        data: Quotation[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
}>();

const accept = (quotation: Quotation): void => {
    router.post(
        quotationRoutes.accept(quotation.id).url,
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Your quotes" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">Your quotes</h1>
            <p class="text-muted-foreground text-sm">
                Prices sellers have quoted you for larger quantities. Accept one
                and it goes into your cart at that price.
            </p>
        </header>

        <Card v-if="quotations.data.length === 0">
            <CardContent class="flex flex-col items-center gap-3 py-12">
                <FileText
                    class="text-muted-foreground size-8"
                    aria-hidden="true"
                />
                <p class="text-muted-foreground text-sm">
                    You have not asked anyone for a price yet. Buying several of
                    something? Ask the seller what they can do.
                </p>
                <Button as-child>
                    <Link :href="home()">Browse parts</Link>
                </Button>
            </CardContent>
        </Card>

        <ul v-else class="space-y-4">
            <li v-for="quotation in quotations.data" :key="quotation.id">
                <Card>
                    <CardContent class="flex flex-col gap-4 p-4 sm:flex-row">
                        <Link
                            :href="listings.show(quotation.listing.slug).url"
                            class="bg-muted aspect-square size-24 shrink-0 overflow-hidden rounded-md"
                        >
                            <img
                                v-if="quotation.listing.thumbnail_url"
                                :src="quotation.listing.thumbnail_url"
                                :alt="quotation.listing.name"
                                loading="lazy"
                                class="size-full object-cover"
                            />
                            <div
                                v-else
                                class="text-muted-foreground flex size-full items-center justify-center"
                            >
                                <ImageOff class="size-5" aria-hidden="true" />
                            </div>
                        </Link>

                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge
                                    :variant="quotation.status.variant"
                                    :title="quotation.status.description"
                                >
                                    {{ quotation.status.label }}
                                </Badge>
                                <span class="text-muted-foreground text-xs">
                                    {{ quotation.quantity }} ×
                                </span>
                            </div>

                            <h2 class="font-medium">
                                <Link
                                    :href="
                                        listings.show(quotation.listing.slug)
                                            .url
                                    "
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ quotation.listing.name }}
                                </Link>
                            </h2>

                            <p
                                v-if="quotation.seller"
                                class="text-muted-foreground text-xs"
                            >
                                <Link
                                    :href="
                                        sellers.show(quotation.seller.slug).url
                                    "
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ quotation.seller.business_name }}
                                </Link>
                            </p>

                            <p
                                v-if="quotation.message"
                                class="text-muted-foreground text-sm italic"
                            >
                                "{{ quotation.message }}"
                            </p>

                            <!-- The answer, when there is one. -->
                            <template
                                v-if="
                                    quotation.quoted_unit_price_ngwee !== null
                                "
                            >
                                <p class="text-sm">
                                    <span class="text-lg font-semibold">
                                        <Money
                                            :amount="
                                                quotation.quoted_unit_price_ngwee
                                            "
                                        />
                                    </span>
                                    each ·
                                    <Money
                                        :amount="quotation.total_ngwee ?? 0"
                                    />
                                    for {{ quotation.quantity }}
                                </p>

                                <p
                                    class="text-muted-foreground text-xs line-through"
                                >
                                    Listed at
                                    <Money
                                        :amount="
                                            quotation.listing
                                                .current_price_ngwee
                                        "
                                    />
                                    each
                                </p>

                                <p
                                    v-if="quotation.valid_until"
                                    class="inline-flex items-center gap-1.5 text-xs"
                                    :class="
                                        quotation.has_expired
                                            ? 'text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    <CalendarClock
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{
                                        quotation.has_expired
                                            ? 'Expired'
                                            : 'Valid until'
                                    }}
                                    {{ quotation.valid_until }}
                                </p>

                                <p
                                    v-if="quotation.delivery_note"
                                    class="text-muted-foreground inline-flex items-center gap-1.5 text-xs"
                                >
                                    <Truck
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ quotation.delivery_note }}
                                </p>
                            </template>

                            <p
                                v-if="quotation.decline_reason"
                                class="text-muted-foreground text-sm"
                            >
                                {{ quotation.decline_reason }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center">
                            <!--
                                Offered on the clock, not on the badge: a
                                stale quote still reads "Quoted".
                            -->
                            <Button
                                v-if="quotation.is_acceptable"
                                type="button"
                                @click="accept(quotation)"
                            >
                                Accept quote
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </li>
        </ul>
    </div>
</template>
