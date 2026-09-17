<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ImageOff, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import ListingBadges from '@/components/catalog/ListingBadges.vue';
import ListingStatusBadge from '@/components/catalog/ListingStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import adminListings from '@/routes/admin/listings';
import type {
    ConditionOption,
    InspectionBadge,
    ListingStatusOption,
    SellerListing,
} from '@/types';

/**
 * The listing moderation queue.
 *
 * Unfiltered, this is the queue proper: everything waiting on a decision,
 * oldest first. The filters turn it into a search across everything else.
 */
const props = defineProps<{
    listings: {
        data: Array<
            SellerListing & {
                seller: {
                    id: number;
                    slug: string;
                    business_name: string;
                    type_label: string;
                    verified: boolean;
                };
            }
        >;
    };
    filters: {
        status?: string | null;
        search?: string | null;
        condition?: string | null;
        inspection_status?: string | null;
    };
    statuses: ListingStatusOption[];
    conditions: ConditionOption[];
    inspectionStatuses: Array<Pick<InspectionBadge, 'value' | 'label'>>;
    queueSize: number;
}>();

const search = ref(props.filters.search ?? '');

let searchTimer: ReturnType<typeof setTimeout> | undefined;

const reload = (overrides: Record<string, unknown> = {}): void => {
    router.get(
        adminListings.index().url,
        {
            status: props.filters.status || undefined,
            condition: props.filters.condition || undefined,
            inspection_status: props.filters.inspection_status || undefined,
            search: search.value || undefined,
            ...overrides,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
};

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 300);
});
</script>

<template>
    <Head title="Listing moderation" />

    <div class="space-y-6 p-4">
        <Heading
            title="Listing moderation"
            description="Listings waiting on a decision, oldest first. Reject field by field so the seller knows what to fix."
        />

        <div class="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                size="sm"
                :variant="filters.status ? 'outline' : 'default'"
                @click="reload({ status: undefined })"
            >
                Queue
                <span class="ml-1 tabular-nums">{{ queueSize }}</span>
            </Button>
            <Button
                v-for="status in statuses"
                :key="status.value"
                type="button"
                size="sm"
                :variant="
                    filters.status === status.value ? 'default' : 'outline'
                "
                @click="reload({ status: status.value })"
            >
                {{ status.label }}
            </Button>
        </div>

        <div class="flex flex-wrap items-end gap-4">
            <div class="relative max-w-sm flex-1">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search by name or part number"
                    class="pl-9"
                    aria-label="Search listings"
                />
            </div>

            <div class="space-y-1">
                <Label for="condition-filter" class="text-xs">Condition</Label>
                <select
                    id="condition-filter"
                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                    :value="filters.condition ?? ''"
                    @change="
                        reload({
                            condition:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                        })
                    "
                >
                    <option value="">Any condition</option>
                    <option
                        v-for="option in conditions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="space-y-1">
                <Label for="inspection-filter" class="text-xs"
                    >Inspection</Label
                >
                <select
                    id="inspection-filter"
                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                    :value="filters.inspection_status ?? ''"
                    @change="
                        reload({
                            inspection_status:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                        })
                    "
                >
                    <option value="">Any</option>
                    <option
                        v-for="option in inspectionStatuses"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
        </div>

        <div v-if="listings.data.length" class="space-y-3">
            <Card v-for="listing in listings.data" :key="listing.id">
                <CardContent class="flex flex-wrap items-start gap-4 p-4">
                    <div
                        class="bg-muted size-20 shrink-0 overflow-hidden rounded border"
                    >
                        <img
                            v-if="listing.photos[0]?.thumb"
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
                            <ListingBadges
                                :condition="listing.condition"
                                :inspection="listing.inspection"
                                compact
                            />
                        </div>

                        <Link
                            :href="adminListings.show(listing.slug).url"
                            class="block font-medium underline-offset-4 hover:underline"
                        >
                            {{ listing.name }}
                        </Link>

                        <p
                            class="text-muted-foreground flex flex-wrap items-center gap-2 text-sm"
                        >
                            {{ listing.seller.business_name }}
                            <Badge variant="outline">
                                {{ listing.seller.type_label }}
                            </Badge>
                            <span v-if="listing.submitted_at">
                                submitted
                                {{
                                    new Date(
                                        listing.submitted_at,
                                    ).toLocaleDateString()
                                }}
                            </span>
                        </p>
                    </div>

                    <div class="space-y-2 text-right">
                        <p class="font-semibold">
                            <Money
                                v-if="listing.variants[0]"
                                :amount="listing.variants[0].price_ngwee"
                            />
                        </p>
                        <Button size="sm" as-child>
                            <Link :href="adminListings.show(listing.slug).url">
                                Review
                            </Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div v-else class="rounded-lg border border-dashed p-12 text-center">
            <p class="text-muted-foreground">
                Nothing waiting. The queue is clear.
            </p>
        </div>
    </div>
</template>
