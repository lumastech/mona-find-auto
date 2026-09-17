<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ImageOff,
    Lock,
    Send,
    Trash2,
    Undo2,
    Video,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import ListingBadges from '@/components/catalog/ListingBadges.vue';
import ListingStatusBadge from '@/components/catalog/ListingStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import sellerListings from '@/routes/seller/listings';
import type {
    CategoryNode,
    ConditionOption,
    DuplicateWarning,
    LabelledValue,
    ListingLimits,
    ListingReviewEntry,
    MakeOption,
    SellerListing,
    VehicleModelOption,
} from '@/types';

/**
 * The listing form.
 *
 * Client-side validation mirrors the server rules field for field, so a
 * seller on a slow connection is told what is wrong before the request goes
 * anywhere — and the server still checks everything, because the browser is
 * not where a rule is enforced.
 *
 * Two fields are deliberately not the seller's to set. A car breaker is shown
 * one condition and told why; the Inspected badge is MonaFind's and is shown
 * read-only.
 */
const props = withDefaults(
    defineProps<{
        listing: SellerListing | null;
        categories: CategoryNode[];
        makes: MakeOption[];
        vehicleModels: VehicleModelOption[];
        conditions: ConditionOption[];
        conditionLocked: string | null;
        sourcingOptions: LabelledValue[];
        fuelTypes: LabelledValue[];
        transmissions: LabelledValue[];
        driveTypes: LabelledValue[];
        bodyTypes: LabelledValue[];
        limits: ListingLimits;
        history?: ListingReviewEntry[];
        duplicateWarning?: DuplicateWarning | null;
    }>(),
    { history: () => [], duplicateWarning: null },
);

const isNew = computed(() => props.listing === null);

/**
 * A listing sitting in the moderation queue is frozen, and so is an archived
 * one: `ProductPolicy::update()` refuses every write on it. The form and the
 * media pickers have to say so themselves, or the seller picks a photo and
 * gets a 403 page back instead of an upload.
 */
const canEdit = computed(
    () => props.listing === null || props.listing.status.editable,
);

const form = reactive({
    name: props.listing?.name ?? '',
    description: props.listing?.description ?? '',
    category_id: props.listing?.category_id ?? '',
    make_id: props.listing?.make_id ?? '',
    vehicle_model_id: props.listing?.vehicle_model_id ?? '',
    year_from: props.listing?.year_from ?? '',
    year_to: props.listing?.year_to ?? '',
    condition:
        props.listing?.condition.value ??
        props.conditionLocked ??
        props.conditions[0]?.value ??
        '',
    sourcing: props.listing?.sourcing ?? 'aftermarket',
    part_number: props.listing?.part_number ?? '',
    oem_number: props.listing?.oem_number ?? '',
    engine_size_cc: props.listing?.engine_size_cc ?? '',
    engine_code: props.listing?.engine_code ?? '',
    fuel_type: props.listing?.fuel_type ?? '',
    transmission: props.listing?.transmission ?? '',
    drive_type: props.listing?.drive_type ?? '',
    body_type: props.listing?.body_type ?? '',
    trim: props.listing?.trim ?? '',
    chassis_compatibility: props.listing?.chassis_compatibility ?? '',
    warranty_text: props.listing?.warranty_text ?? '',
    delivery_available: props.listing?.delivery_available ?? false,
    price: props.listing?.variants[0]
        ? (props.listing.variants[0].price_ngwee / 100).toFixed(2)
        : '',
    quantity: props.listing?.variants[0]?.quantity ?? 1,
});

/** Only the models belonging to the chosen make; anything else is noise. */
const modelsForMake = computed(() =>
    props.vehicleModels.filter(
        (model) => String(model.make_id) === String(form.make_id),
    ),
);

/** The leaves of the tree. Listings hang off those, not off headings. */
const categoryOptions = computed(() => {
    const flatten = (nodes: CategoryNode[]): CategoryNode[] =>
        nodes.flatMap((node) => [node, ...flatten(node.children)]);

    return flatten(props.categories);
});

/**
 * The same rules the server applies, checked here so a seller is not made to
 * wait for a round trip to be told a name is too short.
 */
const clientErrors = computed<Record<string, string>>(() => {
    const errors: Record<string, string> = {};

    if (form.name.trim().length < 5) {
        errors.name =
            'Say what the part is — a buyer searching for it needs more than a word.';
    }

    if (form.description.trim().length < 20) {
        errors.description =
            'Describe the part so a buyer knows what they are getting.';
    }

    if (!form.category_id) {
        errors.category_id = 'Choose the category this part belongs in.';
    }

    if (!(Number(form.price) > 0)) {
        errors.price = 'A listing needs a price above zero.';
    }

    if (
        form.year_from &&
        form.year_to &&
        Number(form.year_to) < Number(form.year_from)
    ) {
        errors.year_to =
            'The last year a part fits cannot be before the first.';
    }

    return errors;
});

const showClientErrors = ref(false);

const canSubmitForm = computed(
    () => Object.keys(clientErrors.value).length === 0,
);

const errorFor = (
    field: string,
    serverErrors: Record<string, string>,
): string | undefined =>
    serverErrors[field] ??
    (showClientErrors.value ? clientErrors.value[field] : undefined);

const photoInput = ref<HTMLInputElement | null>(null);
const videoInput = ref<HTMLInputElement | null>(null);

const uploadPhotos = (): void => {
    const files = photoInput.value?.files;

    if (!files?.length || !props.listing || !canEdit.value) {
        return;
    }

    router.post(
        sellerListings.photos.store(props.listing.slug).url,
        { photos: Array.from(files) },
        { preserveScroll: true, forceFormData: true },
    );
};

const uploadVideo = (): void => {
    const file = videoInput.value?.files?.[0];

    if (!file || !props.listing || !canEdit.value) {
        return;
    }

    router.post(
        sellerListings.video.store(props.listing.slug).url,
        { video: file },
        { preserveScroll: true, forceFormData: true },
    );
};
</script>

<template>
    <Head :title="isNew ? 'New listing' : listing!.name" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="isNew ? 'New listing' : 'Edit listing'"
                description="Photos and a clear description are what sell a part. Everything else helps a buyer find it."
            />

            <div v-if="listing" class="flex flex-wrap items-center gap-2">
                <ListingStatusBadge :status="listing.status" />
                <ListingBadges
                    :condition="listing.condition"
                    :inspection="listing.inspection"
                    compact
                />
            </div>
        </div>

        <Alert v-if="listing?.rejection_reason" variant="destructive">
            <AlertTitle>MonaFind sent this back</AlertTitle>
            <AlertDescription class="space-y-2">
                <p>{{ listing.rejection_reason }}</p>
                <ul
                    v-if="Object.keys(listing.rejection_fields).length"
                    class="list-inside list-disc"
                >
                    <li
                        v-for="(reason, field) in listing.rejection_fields"
                        :key="field"
                    >
                        <span class="font-medium">{{ field }}:</span>
                        {{ reason }}
                    </li>
                </ul>
            </AlertDescription>
        </Alert>

        <Alert v-if="duplicateWarning">
            <AlertTriangle class="size-4" aria-hidden="true" />
            <AlertTitle>You already list this part number</AlertTitle>
            <AlertDescription>
                {{ duplicateWarning.count }} other listing<span
                    v-if="duplicateWarning.count > 1"
                    >s</span
                >
                of yours carry part number
                {{ duplicateWarning.part_number }}. That is fine if you have
                more than one — this is only a heads-up.
            </AlertDescription>
        </Alert>

        <Alert v-if="listing && !canEdit">
            <Lock class="size-4" aria-hidden="true" />
            <AlertTitle>
                {{
                    listing.status.value === 'pending_review'
                        ? 'Locked while MonaFind reviews it'
                        : 'This listing can no longer be edited'
                }}
            </AlertTitle>
            <AlertDescription>
                {{ listing.status.guidance }}
                <template v-if="listing.status.value === 'pending_review'">
                    Withdraw it from review below to change the details or the
                    photos.
                </template>
            </AlertDescription>
        </Alert>

        <Form
            v-bind="
                isNew
                    ? sellerListings.store.form()
                    : sellerListings.update.form(listing!.slug)
            "
            v-slot="{ errors, processing }"
            class="space-y-6"
            @submit="showClientErrors = true"
        >
            <!--
                A frozen listing is frozen field by field: the server
                refuses the save, so nothing here should look writable.
            -->
            <fieldset
                :disabled="!canEdit"
                class="space-y-6 disabled:opacity-70"
            >
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >What the part is</CardTitle
                        >
                    </CardHeader>
                    <CardContent class="grid gap-6 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="name">Listing name</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                name="name"
                                required
                                placeholder="Toyota Hilux 2KD fuel injector"
                            />
                            <InputError :message="errorFor('name', errors)" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="description">Description</Label>
                            <textarea
                                id="description"
                                v-model="form.description"
                                name="description"
                                rows="6"
                                class="border-input bg-background rounded-md border p-3 text-sm"
                                placeholder="What it came off, what condition it is in, what is and is not included."
                            />
                            <InputError
                                :message="errorFor('description', errors)"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="category_id">Category</Label>
                            <select
                                id="category_id"
                                v-model="form.category_id"
                                name="category_id"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option value="">Choose a category</option>
                                <option
                                    v-for="option in categoryOptions"
                                    :key="option.id"
                                    :value="option.id"
                                    :disabled="!option.selectable"
                                >
                                    {{ '— '.repeat(option.depth)
                                    }}{{ option.name }}
                                </option>
                            </select>
                            <InputError
                                :message="errorFor('category_id', errors)"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="condition">Condition</Label>
                            <select
                                id="condition"
                                v-model="form.condition"
                                name="condition"
                                :disabled="conditionLocked !== null"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm disabled:opacity-70"
                            >
                                <option
                                    v-for="option in conditions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <p
                                v-if="conditionLocked"
                                class="text-muted-foreground text-xs"
                            >
                                Everything you sell comes off a scrapped
                                vehicle, so your listings carry the Car Breaker
                                badge.
                            </p>
                            <InputError
                                :message="errorFor('condition', errors)"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="sourcing">Part source</Label>
                            <select
                                id="sourcing"
                                v-model="form.sourcing"
                                name="sourcing"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option
                                    v-for="option in sourcingOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="part_number">Part number</Label>
                            <Input
                                id="part_number"
                                v-model="form.part_number"
                                name="part_number"
                                placeholder="23670-0L050"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="oem_number">OEM number</Label>
                            <Input
                                id="oem_number"
                                v-model="form.oem_number"
                                name="oem_number"
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">What it fits</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-6 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="make_id">Make</Label>
                            <select
                                id="make_id"
                                v-model="form.make_id"
                                name="make_id"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option value="">Fits any make</option>
                                <option
                                    v-for="make in makes"
                                    :key="make.id"
                                    :value="make.id"
                                >
                                    {{ make.name }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="vehicle_model_id">Model</Label>
                            <select
                                id="vehicle_model_id"
                                v-model="form.vehicle_model_id"
                                name="vehicle_model_id"
                                :disabled="!form.make_id"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm disabled:opacity-70"
                            >
                                <option value="">Any model</option>
                                <option
                                    v-for="model in modelsForMake"
                                    :key="model.id"
                                    :value="model.id"
                                >
                                    {{ model.name }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="year_from">First year</Label>
                            <Input
                                id="year_from"
                                v-model="form.year_from"
                                name="year_from"
                                type="number"
                                min="1950"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="year_to">Last year</Label>
                            <Input
                                id="year_to"
                                v-model="form.year_to"
                                name="year_to"
                                type="number"
                                min="1950"
                            />
                            <InputError
                                :message="errorFor('year_to', errors)"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="engine_size_cc">Engine size (cc)</Label>
                            <Input
                                id="engine_size_cc"
                                v-model="form.engine_size_cc"
                                name="engine_size_cc"
                                type="number"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="engine_code">Engine code</Label>
                            <Input
                                id="engine_code"
                                v-model="form.engine_code"
                                name="engine_code"
                                placeholder="2KD-FTV"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fuel_type">Fuel type</Label>
                            <select
                                id="fuel_type"
                                v-model="form.fuel_type"
                                name="fuel_type"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option value="">Not specified</option>
                                <option
                                    v-for="option in fuelTypes"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="transmission">Transmission</Label>
                            <select
                                id="transmission"
                                v-model="form.transmission"
                                name="transmission"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option value="">Not specified</option>
                                <option
                                    v-for="option in transmissions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="drive_type">Drive type</Label>
                            <select
                                id="drive_type"
                                v-model="form.drive_type"
                                name="drive_type"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option value="">Not specified</option>
                                <option
                                    v-for="option in driveTypes"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="body_type">Body type</Label>
                            <select
                                id="body_type"
                                v-model="form.body_type"
                                name="body_type"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option value="">Not specified</option>
                                <option
                                    v-for="option in bodyTypes"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="trim">Trim or variant</Label>
                            <Input id="trim" v-model="form.trim" name="trim" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="chassis_compatibility">
                                Chassis or VIN codes this fits
                            </Label>
                            <textarea
                                id="chassis_compatibility"
                                v-model="form.chassis_compatibility"
                                name="chassis_compatibility"
                                rows="2"
                                class="border-input bg-background rounded-md border p-3 text-sm"
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Price and stock</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-6 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="price">Price (K, VAT included)</Label>
                            <Input
                                id="price"
                                v-model="form.price"
                                name="price"
                                inputmode="decimal"
                                placeholder="1250.00"
                            />
                            <p class="text-muted-foreground text-xs">
                                The price a buyer pays. VAT on the goods is
                                yours to account for; MonaFind invoices only its
                                commission.
                            </p>
                            <InputError :message="errorFor('price', errors)" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="quantity">Quantity in stock</Label>
                            <Input
                                id="quantity"
                                v-model="form.quantity"
                                name="quantity"
                                type="number"
                                min="0"
                            />
                            <InputError
                                :message="errorFor('quantity', errors)"
                            />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="warranty_text">Warranty</Label>
                            <Input
                                id="warranty_text"
                                v-model="form.warranty_text"
                                name="warranty_text"
                                placeholder="30-day warranty on fitment."
                            />
                        </div>

                        <div class="flex items-center gap-2 sm:col-span-2">
                            <Checkbox
                                id="delivery_available"
                                v-model="form.delivery_available"
                                name="delivery_available"
                            />
                            <Label for="delivery_available">
                                I can deliver this part
                            </Label>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ isNew ? 'Save draft' : 'Save changes' }}
                    </Button>

                    <p
                        v-if="showClientErrors && !canSubmitForm"
                        class="text-destructive text-sm"
                    >
                        Fix the fields marked above.
                    </p>

                    <Button variant="ghost" as-child>
                        <Link :href="sellerListings.index().url"
                            >Back to listings</Link
                        >
                    </Button>
                </div>
            </fieldset>
        </Form>

        <template v-if="listing">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Photos</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <p class="text-muted-foreground text-sm">
                        At least {{ limits.min_photos }}, at most
                        {{ limits.max_photos }}. The first photo is the one
                        buyers see in search results.
                    </p>

                    <div
                        v-if="listing.photos.length"
                        class="flex flex-wrap gap-3"
                    >
                        <figure
                            v-for="photo in listing.photos"
                            :key="photo.id"
                            class="relative"
                        >
                            <img
                                v-if="photo.thumb"
                                :src="photo.thumb"
                                :alt="photo.name ?? listing.name"
                                class="size-24 rounded border object-cover"
                            />
                            <div
                                v-else
                                class="bg-muted text-muted-foreground flex size-24 items-center justify-center rounded border"
                                :title="`${photo.name ?? 'Photo'} is still being processed`"
                            >
                                <ImageOff class="size-5" aria-hidden="true" />
                            </div>
                            <Link
                                v-if="canEdit"
                                :href="
                                    sellerListings.photos.destroy([
                                        listing.slug,
                                        photo.id,
                                    ])
                                "
                                method="delete"
                                as="button"
                                preserve-scroll
                                class="bg-background absolute -top-2 -right-2 rounded-full border p-1"
                                :aria-label="`Remove photo ${photo.name}`"
                            >
                                <Trash2
                                    class="text-destructive size-3.5"
                                    aria-hidden="true"
                                />
                            </Link>
                        </figure>
                    </div>

                    <p
                        v-else
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        <ImageOff class="size-4" aria-hidden="true" />
                        No photos yet. A listing cannot be reviewed without one.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <input
                            v-if="canEdit"
                            ref="photoInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            class="text-sm"
                            @change="uploadPhotos"
                        />
                        <p v-else class="text-muted-foreground text-sm">
                            Photos are locked while the listing is
                            {{ listing.status.label.toLowerCase() }}.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Video</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <p class="text-muted-foreground text-sm">
                        Optional, and at most
                        {{ limits.max_video_seconds }} seconds. A walk round the
                        part answers most of the questions buyers ask.
                    </p>

                    <p
                        v-if="listing.video"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Video class="size-4" aria-hidden="true" />
                        {{ listing.video.name }}
                        <span
                            v-if="!listing.video.processed"
                            class="text-muted-foreground"
                        >
                            — still processing
                        </span>
                        <Link
                            v-if="canEdit"
                            :href="sellerListings.video.destroy(listing.slug)"
                            method="delete"
                            as="button"
                            preserve-scroll
                            class="text-destructive underline-offset-4 hover:underline"
                        >
                            Remove
                        </Link>
                    </p>

                    <input
                        v-if="canEdit"
                        ref="videoInput"
                        type="file"
                        accept="video/mp4,video/quicktime,video/webm"
                        class="text-sm"
                        @change="uploadVideo"
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base"
                        >Where this listing is</CardTitle
                    >
                </CardHeader>
                <CardContent class="space-y-4">
                    <p class="text-muted-foreground text-sm">
                        {{ listing.status.guidance }}
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="
                                ['draft', 'rejected'].includes(
                                    listing.status.value,
                                )
                            "
                            as-child
                        >
                            <Link
                                :href="sellerListings.submit(listing.slug)"
                                method="post"
                                as="button"
                                preserve-scroll
                            >
                                <Send class="size-4" aria-hidden="true" />
                                Send for review
                            </Link>
                        </Button>

                        <Button
                            v-if="listing.status.value === 'pending_review'"
                            variant="outline"
                            as-child
                        >
                            <Link
                                :href="sellerListings.withdraw(listing.slug)"
                                method="post"
                                as="button"
                                preserve-scroll
                            >
                                <Undo2 class="size-4" aria-hidden="true" />
                                Withdraw from review
                            </Link>
                        </Button>

                        <Button
                            v-if="listing.status.value === 'published'"
                            variant="outline"
                            as-child
                        >
                            <Link
                                :href="sellerListings.unpublish(listing.slug)"
                                method="post"
                                as="button"
                                preserve-scroll
                            >
                                Hide from buyers
                            </Link>
                        </Button>

                        <Button
                            v-if="listing.status.value === 'unpublished'"
                            as-child
                        >
                            <Link
                                :href="sellerListings.republish(listing.slug)"
                                method="post"
                                as="button"
                                preserve-scroll
                            >
                                Put back on sale
                            </Link>
                        </Button>

                        <Button variant="ghost" as-child>
                            <Link
                                :href="sellerListings.destroy(listing.slug)"
                                method="delete"
                                as="button"
                            >
                                Archive listing
                            </Link>
                        </Button>
                    </div>

                    <ol v-if="history.length" class="space-y-2 text-sm">
                        <li
                            v-for="entry in history"
                            :key="entry.id"
                            class="border-l-2 pl-3"
                        >
                            <p class="font-medium">{{ entry.summary }}</p>
                            <p
                                v-if="entry.reason"
                                class="text-muted-foreground"
                            >
                                {{ entry.reason }}
                            </p>
                        </li>
                    </ol>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
