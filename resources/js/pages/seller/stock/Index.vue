<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCheck, Download, ImageOff, Search, Upload } from '@lucide/vue';
import { ref, watch } from 'vue';
import FreshnessBadge from '@/components/inventory/FreshnessBadge.vue';
import StockConfirmationBanner from '@/components/inventory/StockConfirmationBanner.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import sellerStock from '@/routes/seller/stock';
import type { FreshnessStateOption, StockListing, StockSummary } from '@/types';

/**
 * The stock screen: what a seller has, and how long since they said so.
 *
 * Worst first. A seller who opens this once a week wants the listings that
 * are about to be hidden at the top, not the ones they confirmed this
 * morning — so the server sorts by freshness and this page does not reorder
 * it.
 *
 * Every row can be confirmed on its own or corrected in place, but the
 * banner's one tap is the path most sellers should take and the page is laid
 * out to say so.
 */
const props = defineProps<{
    listings: { data: StockListing[]; total?: number };
    filters: {
        freshness?: string | null;
        search?: string | null;
        attention?: boolean | null;
    };
    freshnessStates: FreshnessStateOption[];
    summary: StockSummary;
    latestImport: number | null;
}>();

const search = ref(props.filters.search ?? '');

let searchTimer: ReturnType<typeof setTimeout> | undefined;

const reload = (overrides: Record<string, unknown> = {}) =>
    router.get(
        sellerStock.index().url,
        {
            freshness: props.filters.freshness || undefined,
            attention: props.filters.attention || undefined,
            search: search.value || undefined,
            ...overrides,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload(), 300);
});

/**
 * One row's quantity edit. Kept per variant rather than as a page-wide form
 * so a seller correcting the third row does not resubmit the first two.
 */
const editing = ref<number | null>(null);
const quantityForm = useForm({ variant_id: 0, quantity: 0 });

const startEditing = (variantId: number, quantity: number) => {
    editing.value = variantId;
    quantityForm.variant_id = variantId;
    quantityForm.quantity = quantity;
};

const saveQuantity = (listing: StockListing) =>
    quantityForm.put(sellerStock.update(listing.slug).url, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });

const confirmForm = useForm({});

const confirmOne = (listing: StockListing) =>
    confirmForm.post(sellerStock.confirm.product(listing.slug).url, {
        preserveScroll: true,
    });

/**
 * The bulk upload. Sends the file for checking only — the report it lands on
 * is where the seller decides whether any of it is applied.
 */
const uploadForm = useForm<{ file: File | null }>({ file: null });

const onFileChosen = (event: Event) => {
    const input = event.target as HTMLInputElement;

    uploadForm.file = input.files?.[0] ?? null;
};

const uploadFile = () =>
    uploadForm.post(sellerStock.imports.store().url, { forceFormData: true });
</script>

<template>
    <Head title="Stock" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Stock"
                description="Confirm your stock every few days so buyers know your listings are real."
            />

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <a
                        :href="
                            sellerStock.template.url({
                                query: { format: 'xlsx' },
                            })
                        "
                    >
                        <Download class="size-4" aria-hidden="true" />
                        Download stock file
                    </a>
                </Button>
                <Button variant="outline" as-child>
                    <a href="#upload">
                        <Upload class="size-4" aria-hidden="true" />
                        Upload stock file
                    </a>
                </Button>
            </div>
        </div>

        <StockConfirmationBanner :summary="summary" />

        <Alert v-if="latestImport">
            <AlertTitle>You have an upload waiting</AlertTitle>
            <AlertDescription class="space-y-3">
                <p>
                    A stock file has been checked and is waiting for you to
                    apply it.
                </p>
                <Button size="sm" as-child>
                    <Link :href="sellerStock.imports.show(latestImport).url">
                        Read the report
                    </Link>
                </Button>
            </AlertDescription>
        </Alert>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardContent class="p-4">
                    <p class="text-muted-foreground text-xs">Confirmed</p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ summary.fresh }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <p class="text-muted-foreground text-xs">
                        Needs confirming
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ summary.needs_confirmation }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <p class="text-muted-foreground text-xs">Low stock</p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ summary.low_stock }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <p class="text-muted-foreground text-xs">Out of stock</p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ summary.out_of_stock }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                size="sm"
                :variant="
                    filters.freshness || filters.attention
                        ? 'outline'
                        : 'default'
                "
                @click="reload({ freshness: undefined, attention: undefined })"
            >
                All
            </Button>
            <Button
                type="button"
                size="sm"
                :variant="filters.attention ? 'default' : 'outline'"
                @click="reload({ freshness: undefined, attention: true })"
            >
                Needs me
            </Button>
            <Button
                v-for="state in freshnessStates"
                :key="state.value"
                type="button"
                size="sm"
                :variant="
                    filters.freshness === state.value ? 'default' : 'outline'
                "
                @click="
                    reload({ freshness: state.value, attention: undefined })
                "
            >
                {{ state.label }}
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
                aria-label="Search your stock"
            />
        </div>

        <div v-if="listings.data.length" class="space-y-3">
            <Card v-for="listing in listings.data" :key="listing.id">
                <CardContent class="space-y-4 p-4">
                    <div class="flex flex-wrap items-start gap-4">
                        <div
                            class="bg-muted size-16 shrink-0 overflow-hidden rounded border"
                        >
                            <img
                                v-if="listing.thumbnail_url"
                                :src="listing.thumbnail_url"
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
                            <p class="font-medium">{{ listing.name }}</p>
                            <FreshnessBadge :freshness="listing.freshness" />
                            <p
                                v-if="listing.freshness.hidden"
                                class="text-destructive text-sm"
                            >
                                {{ listing.freshness.description }}
                            </p>
                        </div>

                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="confirmForm.processing"
                            @click="confirmOne(listing)"
                        >
                            <CheckCheck class="size-4" aria-hidden="true" />
                            Still accurate
                        </Button>
                    </div>

                    <div class="divide-y rounded border">
                        <div
                            v-for="variant in listing.variants"
                            :key="variant.id"
                            class="flex flex-wrap items-center gap-3 p-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">
                                    {{ variant.name ?? listing.name }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{ variant.sku }} ·
                                    <Money :amount="variant.price_ngwee" />
                                </p>
                            </div>

                            <Badge :variant="variant.level.variant">
                                {{ variant.level.label }}
                            </Badge>

                            <template v-if="editing === variant.id">
                                <div class="flex items-center gap-2">
                                    <Label
                                        :for="`quantity-${variant.id}`"
                                        class="sr-only"
                                    >
                                        Quantity for {{ variant.sku }}
                                    </Label>
                                    <Input
                                        :id="`quantity-${variant.id}`"
                                        v-model.number="quantityForm.quantity"
                                        type="number"
                                        min="0"
                                        class="w-24"
                                    />
                                    <Button
                                        type="button"
                                        size="sm"
                                        :disabled="quantityForm.processing"
                                        @click="saveQuantity(listing)"
                                    >
                                        Save
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="editing = null"
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </template>

                            <template v-else>
                                <span class="w-16 text-right tabular-nums">
                                    {{ variant.quantity }}
                                </span>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="
                                        startEditing(
                                            variant.id,
                                            variant.quantity,
                                        )
                                    "
                                >
                                    Change
                                </Button>
                            </template>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div v-else class="rounded-lg border border-dashed p-12 text-center">
            <p class="text-muted-foreground">
                Nothing here yet. Your listings appear once you have created
                them.
            </p>
        </div>

        <!--
            Bulk update. Download, walk the shelves with it, upload it back —
            which is the stock take a parts shop actually runs. Nothing is
            written until the seller has read the report.
        -->
        <Card id="upload">
            <CardContent class="space-y-4 p-4">
                <div>
                    <h2 class="font-medium">Update stock from a file</h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Download the file, correct the quantities and prices,
                        and upload it back. You will see any problem rows before
                        anything changes.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <a
                            :href="
                                sellerStock.template.url({
                                    query: { format: 'xlsx' },
                                })
                            "
                        >
                            <Download class="size-4" aria-hidden="true" />
                            XLSX
                        </a>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <a
                            :href="
                                sellerStock.template.url({
                                    query: { format: 'csv' },
                                })
                            "
                        >
                            <Download class="size-4" aria-hidden="true" />
                            CSV
                        </a>
                    </Button>
                </div>

                <form class="space-y-3" @submit.prevent="uploadFile">
                    <div class="space-y-1">
                        <Label for="stock-file">Stock file</Label>
                        <Input
                            id="stock-file"
                            type="file"
                            accept=".csv,.xlsx"
                            @change="onFileChosen"
                        />
                        <InputError :message="uploadForm.errors.file" />
                    </div>

                    <Button
                        type="submit"
                        size="sm"
                        :disabled="!uploadForm.file || uploadForm.processing"
                    >
                        <Upload class="size-4" aria-hidden="true" />
                        Check this file
                    </Button>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
