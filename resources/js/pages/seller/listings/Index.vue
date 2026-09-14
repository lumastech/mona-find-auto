<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ImageOff, Plus, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import ListingBadges from '@/components/catalog/ListingBadges.vue';
import ListingStatusBadge from '@/components/catalog/ListingStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import sellerListings from '@/routes/seller/listings';
import type { ListingStatusOption, SellerListing } from '@/types';

/**
 * A seller's listings, filtered by where each one has got to.
 *
 * The status tabs are the spine of this page: a seller's real question is
 * "what is waiting on me?", and rejected and draft listings are the answer.
 */
const props = defineProps<{
    listings: { data: SellerListing[]; total?: number };
    filters: { status?: string | null; search?: string | null };
    statuses: ListingStatusOption[];
    counts: Record<string, number>;
    canList: boolean;
}>();

const search = ref(props.filters.search ?? '');

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (term) => {
    clearTimeout(searchTimer);

    searchTimer = setTimeout(() => {
        router.get(
            sellerListings.index().url,
            {
                status: props.filters.status || undefined,
                search: term || undefined,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    }, 300);
});

const filterTo = (status: string | null) =>
    router.get(
        sellerListings.index().url,
        { status: status ?? undefined, search: search.value || undefined },
        { preserveState: true, replace: true },
    );
</script>

<template>
    <Head title="Listings" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Listings"
                description="What you are selling on MonaFindAuto, and where each listing has got to."
            />

            <Button as-child>
                <Link :href="sellerListings.create().url">
                    <Plus class="size-4" aria-hidden="true" />
                    New listing
                </Link>
            </Button>
        </div>

        <Alert v-if="!canList">
            <AlertTitle
                >Your shop cannot send listings for review yet</AlertTitle
            >
            <AlertDescription>
                You can keep writing drafts. Once MonaFind has your application
                in hand you will be able to submit them.
            </AlertDescription>
        </Alert>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                size="sm"
                :variant="filters.status ? 'outline' : 'default'"
                @click="filterTo(null)"
            >
                All
            </Button>
            <Button
                v-for="status in statuses"
                :key="status.value"
                type="button"
                size="sm"
                :variant="
                    filters.status === status.value ? 'default' : 'outline'
                "
                @click="filterTo(status.value)"
            >
                {{ status.label }}
                <span class="text-muted-foreground ml-1 tabular-nums">
                    {{ counts[status.value] ?? 0 }}
                </span>
            </Button>
        </div>

        <div class="relative max-w-sm">
            <Search
                class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                aria-hidden="true"
            />
            <Input
                v-model="search"
                type="search"
                placeholder="Search by name or part number"
                class="pl-9"
                aria-label="Search your listings"
            />
        </div>

        <div v-if="listings.data.length" class="space-y-3">
            <Card v-for="listing in listings.data" :key="listing.id">
                <CardContent class="flex flex-wrap items-start gap-4 p-4">
                    <div
                        class="bg-muted size-20 shrink-0 overflow-hidden rounded border"
                    >
                        <img
                            v-if="listing.photos[0]"
                            :src="listing.photos[0].thumb"
                            :alt="listing.name"
                            class="size-full object-cover"
                        />
                        <div
                            v-else
                            class="text-muted-foreground flex size-full items-center justify-center"
                        >
                            <ImageOff class="size-5" aria-hidden="true" />
                        </div>
                    </div>

                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <ListingStatusBadge :status="listing.status" />
                            <!-- The same pair the storefront shows, so a seller sees what a buyer sees. -->
                            <ListingBadges
                                :condition="listing.condition"
                                :inspection="listing.inspection"
                                compact
                            />
                        </div>

                        <Link
                            :href="sellerListings.edit(listing.slug).url"
                            class="block font-medium underline-offset-4 hover:underline"
                        >
                            {{ listing.name }}
                        </Link>

                        <p class="text-muted-foreground text-sm">
                            {{ listing.status.guidance }}
                        </p>

                        <p
                            v-if="listing.rejection_reason"
                            class="text-destructive text-sm"
                        >
                            {{ listing.rejection_reason }}
                        </p>
                    </div>

                    <div class="space-y-1 text-right">
                        <p class="font-semibold">
                            <Money
                                v-if="listing.variants[0]"
                                :amount="listing.variants[0].price_ngwee"
                            />
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ listing.variants[0]?.quantity ?? 0 }} in stock
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div v-else class="rounded-lg border border-dashed p-12 text-center">
            <p class="text-muted-foreground">
                Nothing here yet. Your first listing takes about two minutes.
            </p>
            <Button class="mt-4" as-child>
                <Link :href="sellerListings.create().url">New listing</Link>
            </Button>
        </div>
    </div>
</template>
