<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { BadgeCheck, Check, ScanEye, X } from '@lucide/vue';
import { ref } from 'vue';
import ListingBadges from '@/components/catalog/ListingBadges.vue';
import ListingStatusBadge from '@/components/catalog/ListingStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import adminListings from '@/routes/admin/listings';
import adminSellers from '@/routes/admin/sellers';
import type {
    BreadcrumbNode,
    InspectionBadge,
    ListingReviewEntry,
    ProductDetail,
    SellerListing,
} from '@/types';

/**
 * Reviewing one listing.
 *
 * The photos sit beside the fields they are meant to show, because that is
 * the comparison a moderator is actually making — is this description of a
 * part the same part as the one in the photograph?
 *
 * Rejection is field by field. A seller told "rejected" resubmits the same
 * listing; a seller told "photos: too dark to see the part" fixes the photos.
 */
const props = defineProps<{
    listing: SellerListing & { storefront: ProductDetail };
    seller: {
        id: number;
        slug: string;
        business_name: string;
        type_label: string;
        verified: boolean;
        verification_label: string;
        sells_breaker_stock: boolean;
        location: string;
    };
    breadcrumb: BreadcrumbNode[];
    history: ListingReviewEntry[];
    rejectableFields: string[];
    inspectionStatuses: Array<Pick<InspectionBadge, 'value' | 'label'>>;
    canModerate: boolean;
    canInspect: boolean;
}>();

const activePhoto = ref(props.listing.photos[0] ?? null);
const rejecting = ref(false);
</script>

<template>
    <Head :title="`Review: ${listing.name}`" />

    <div class="space-y-6 p-4">
        <Heading
            :title="listing.name"
            :description="breadcrumb.map((node) => node.name).join(' → ')"
        />

        <div class="flex flex-wrap items-center gap-2">
            <ListingStatusBadge :status="listing.status" />
            <ListingBadges
                :condition="listing.condition"
                :inspection="listing.inspection"
            />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Photos beside the fields, so the two can actually be compared. -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">
                        Photos ({{ listing.photos.length }})
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        class="bg-muted aspect-4/3 w-full overflow-hidden rounded-lg border"
                    >
                        <img
                            v-if="activePhoto"
                            :src="activePhoto.card"
                            :alt="activePhoto.name ?? listing.name"
                            class="size-full object-contain"
                        />
                        <p
                            v-else
                            class="text-muted-foreground flex size-full items-center justify-center text-sm"
                        >
                            No photos. This listing cannot be published.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="photo in listing.photos"
                            :key="photo.id"
                            type="button"
                            class="focus-visible:ring-ring size-16 overflow-hidden rounded border focus-visible:ring-2 focus-visible:outline-none"
                            :class="{
                                'ring-primary ring-2':
                                    activePhoto?.id === photo.id,
                            }"
                            @click="activePhoto = photo"
                        >
                            <img
                                :src="photo.thumb"
                                :alt="photo.name ?? ''"
                                class="size-full object-cover"
                            />
                        </button>
                    </div>

                    <video
                        v-if="listing.storefront.video?.source"
                        :src="listing.storefront.video.source"
                        :poster="listing.storefront.video.poster ?? undefined"
                        controls
                        preload="none"
                        class="w-full rounded-lg border"
                    />
                </CardContent>
            </Card>

            <div class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >What the seller wrote</CardTitle
                        >
                    </CardHeader>
                    <CardContent class="space-y-4 text-sm">
                        <p class="whitespace-pre-line">
                            {{ listing.description }}
                        </p>

                        <dl class="space-y-1">
                            <div
                                v-for="row in listing.storefront.specification"
                                :key="row.label"
                                class="flex justify-between gap-4 border-b py-1 last:border-0"
                            >
                                <dt class="text-muted-foreground">
                                    {{ row.label }}
                                </dt>
                                <dd>{{ row.value }}</dd>
                            </div>
                        </dl>

                        <p class="text-base font-semibold">
                            <Money
                                v-if="listing.variants[0]"
                                :amount="listing.variants[0].price_ngwee"
                            />
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">The shop</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-2 text-sm">
                        <Link
                            :href="adminSellers.show(seller.slug).url"
                            class="font-medium underline-offset-4 hover:underline"
                        >
                            {{ seller.business_name }}
                        </Link>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                :variant="
                                    seller.verified ? 'default' : 'outline'
                                "
                            >
                                {{ seller.verification_label }}
                            </Badge>
                            <Badge variant="secondary">
                                {{ seller.type_label }}
                            </Badge>
                        </div>
                        <p
                            v-if="seller.sells_breaker_stock"
                            class="text-muted-foreground"
                        >
                            A car breaker: every listing carries the Car Breaker
                            badge automatically.
                        </p>
                        <p class="text-muted-foreground">
                            {{ seller.location }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Card v-if="canInspect">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <ScanEye class="size-4" aria-hidden="true" />
                    Inspection badge
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <p class="text-muted-foreground text-sm">
                    This is separate from publishing. Marking a listing
                    Inspected says MonaFind has looked at the part itself, so
                    record what was checked — the reason is kept on the audit
                    trail.
                </p>

                <Form
                    v-bind="adminListings.inspection.form(listing.slug)"
                    v-slot="{ errors, processing }"
                    class="grid gap-4 sm:grid-cols-[200px_1fr_auto] sm:items-end"
                >
                    <div class="grid gap-2">
                        <Label for="inspection_status">Badge</Label>
                        <select
                            id="inspection_status"
                            name="inspection_status"
                            :value="listing.inspection.value"
                            class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        >
                            <option
                                v-for="option in inspectionStatuses"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="inspection_reason">What was checked</Label>
                        <Input
                            id="inspection_reason"
                            name="reason"
                            required
                            placeholder="Checked the casting number against the OEM part."
                        />
                        <InputError :message="errors.reason" />
                    </div>

                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        Save badge
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <Card v-if="canModerate">
            <CardHeader>
                <CardTitle class="text-base">Decision</CardTitle>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="flex flex-wrap gap-3">
                    <Form
                        v-if="listing.status.value !== 'published'"
                        v-bind="adminListings.publish.form(listing.slug)"
                        v-slot="{ processing }"
                    >
                        <Button type="submit" :disabled="processing">
                            <Check class="size-4" aria-hidden="true" />
                            Publish
                        </Button>
                    </Form>

                    <Button
                        type="button"
                        variant="destructive"
                        @click="rejecting = !rejecting"
                    >
                        <X class="size-4" aria-hidden="true" />
                        {{ rejecting ? 'Cancel rejection' : 'Reject' }}
                    </Button>
                </div>

                <Form
                    v-if="rejecting"
                    v-bind="adminListings.reject.form(listing.slug)"
                    v-slot="{ errors, processing }"
                    class="space-y-4 rounded-lg border p-4"
                >
                    <div class="grid gap-2">
                        <Label for="reason">
                            What the seller needs to fix
                        </Label>
                        <textarea
                            id="reason"
                            name="reason"
                            rows="3"
                            required
                            class="border-input bg-background rounded-md border p-3 text-sm"
                            placeholder="The photos do not show the part clearly enough to identify it."
                        />
                        <InputError :message="errors.reason" />
                    </div>

                    <fieldset class="space-y-3">
                        <legend class="text-sm font-medium">
                            Reasons against particular fields
                        </legend>
                        <p class="text-muted-foreground text-sm">
                            Anything you write here is shown beside that field
                            when the seller reopens the listing.
                        </p>

                        <div
                            v-for="field in rejectableFields"
                            :key="field"
                            class="grid gap-1 sm:grid-cols-[160px_1fr] sm:items-center"
                        >
                            <Label :for="`field-${field}`" class="text-sm">
                                {{ field.replace('_id', '').replace('_', ' ') }}
                            </Label>
                            <Input
                                :id="`field-${field}`"
                                :name="`field_reasons[${field}]`"
                                placeholder="Leave blank if this field is fine"
                            />
                        </div>
                    </fieldset>

                    <div class="grid gap-2">
                        <Label for="note">Internal note</Label>
                        <Input
                            id="note"
                            name="note"
                            placeholder="Never shown to the seller"
                        />
                    </div>

                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        Send rejection
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <Card v-if="history.length">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <BadgeCheck class="size-4" aria-hidden="true" />
                    History
                </CardTitle>
            </CardHeader>
            <CardContent>
                <ol class="space-y-3 text-sm">
                    <li
                        v-for="entry in history"
                        :key="entry.id"
                        class="border-l-2 pl-3"
                    >
                        <p class="font-medium">{{ entry.summary }}</p>
                        <p v-if="entry.reason" class="text-muted-foreground">
                            {{ entry.reason }}
                        </p>
                        <ul
                            v-if="Object.keys(entry.field_reasons).length"
                            class="text-muted-foreground list-inside list-disc"
                        >
                            <li
                                v-for="(reason, field) in entry.field_reasons"
                                :key="field"
                            >
                                {{ field }}: {{ reason }}
                            </li>
                        </ul>
                        <p
                            v-if="entry.note"
                            class="text-muted-foreground italic"
                        >
                            Note: {{ entry.note }}
                        </p>
                    </li>
                </ol>
            </CardContent>
        </Card>
    </div>
</template>
