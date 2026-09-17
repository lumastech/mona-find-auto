<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarClock, ImageOff, Inbox } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import listings from '@/routes/listings';
import sellerQuotations from '@/routes/seller/quotations';
import type { LabelledOption, Quotation } from '@/types';

/**
 * The RFQ inbox.
 *
 * Ordered unanswered-first and then oldest-first: the buyer who has been
 * waiting longest is the one about to give up and go back to WhatsApp. A
 * request nobody replies to costs the platform more than one declined.
 *
 * The reply form is inline rather than behind a page of its own, because
 * answering is three fields and a seller with eleven requests should be able
 * to work down the list without eleven round trips.
 */
const props = defineProps<{
    quotations: {
        data: Quotation[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { status: string | null };
    statusOptions: LabelledOption[];
    awaitingCount: number;
}>();

/** Which request has its reply form open. One at a time. */
const replyingTo = ref<number | null>(null);

const form = useForm({
    unit_price: '',
    valid_until: '',
    delivery_note: '',
});

const declineForm = useForm({ reason: '' });

const openReply = (quotation: Quotation): void => {
    replyingTo.value = quotation.id;

    form.reset();
    form.clearErrors();

    /* A week is the usual answer, and the seller can change it. */
    const inAWeek = new Date();
    inAWeek.setDate(inAWeek.getDate() + 7);
    form.valid_until = inAWeek.toISOString().slice(0, 10);
};

const submitQuote = (quotation: Quotation): void => {
    form.post(sellerQuotations.respond(quotation.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            replyingTo.value = null;
        },
    });
};

const decline = (quotation: Quotation): void => {
    declineForm.post(sellerQuotations.decline(quotation.id).url, {
        preserveScroll: true,
    });
};

const filterBy = (status: string | null): void => {
    router.get(
        sellerQuotations.index().url,
        status === null ? {} : { status },
        { preserveState: true, preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Quote requests" />

    <div class="space-y-6 px-4 sm:px-6 lg:px-8">
        <Heading
            title="Quote requests"
            :description="
                awaitingCount === 0
                    ? 'Nothing waiting on you.'
                    : `${awaitingCount} ${awaitingCount === 1 ? 'buyer is' : 'buyers are'} waiting on a price.`
            "
        />

        <div class="flex flex-wrap gap-2">
            <Button
                type="button"
                size="sm"
                :variant="filters.status === null ? 'default' : 'outline'"
                @click="filterBy(null)"
            >
                All
            </Button>
            <Button
                v-for="option in statusOptions"
                :key="option.value"
                type="button"
                size="sm"
                :variant="
                    filters.status === option.value ? 'default' : 'outline'
                "
                @click="filterBy(option.value)"
            >
                {{ option.label }}
            </Button>
        </div>

        <Card v-if="quotations.data.length === 0">
            <CardContent class="flex flex-col items-center gap-3 py-12">
                <Inbox
                    class="text-muted-foreground size-8"
                    aria-hidden="true"
                />
                <p class="text-muted-foreground text-sm">
                    No quote requests here yet.
                </p>
            </CardContent>
        </Card>

        <ul v-else class="space-y-4">
            <li v-for="quotation in quotations.data" :key="quotation.id">
                <Card>
                    <CardContent class="space-y-4 p-4">
                        <div class="flex gap-4">
                            <Link
                                :href="
                                    listings.show(quotation.listing.slug).url
                                "
                                class="bg-muted aspect-square size-20 shrink-0 overflow-hidden rounded-md"
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
                                    <ImageOff
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                            </Link>

                            <div class="min-w-0 flex-1 space-y-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge
                                        :variant="quotation.status.variant"
                                        :title="quotation.status.description"
                                    >
                                        {{ quotation.status.label }}
                                    </Badge>
                                    <span
                                        v-if="quotation.buyer"
                                        class="text-muted-foreground text-xs"
                                    >
                                        {{ quotation.buyer.name }}
                                    </span>
                                </div>

                                <p class="font-medium">
                                    {{ quotation.quantity }} ×
                                    {{ quotation.listing.name }}
                                </p>

                                <p class="text-muted-foreground text-xs">
                                    Your listed price:
                                    <Money
                                        :amount="
                                            quotation.listing
                                                .current_price_ngwee
                                        "
                                    />
                                    each
                                </p>

                                <p
                                    v-if="quotation.message"
                                    class="text-sm italic"
                                >
                                    "{{ quotation.message }}"
                                </p>

                                <!-- What was already answered. -->
                                <p
                                    v-if="
                                        quotation.quoted_unit_price_ngwee !==
                                        null
                                    "
                                    class="inline-flex flex-wrap items-center gap-1.5 text-sm"
                                >
                                    You quoted
                                    <Money
                                        :amount="
                                            quotation.quoted_unit_price_ngwee
                                        "
                                    />
                                    each
                                    <span
                                        v-if="quotation.valid_until"
                                        class="inline-flex items-center gap-1"
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
                                                ? 'expired'
                                                : 'until'
                                        }}
                                        {{ quotation.valid_until }}
                                    </span>
                                </p>
                            </div>

                            <div
                                v-if="quotation.status.value === 'open'"
                                class="flex shrink-0 flex-col gap-2"
                            >
                                <Button
                                    type="button"
                                    size="sm"
                                    @click="openReply(quotation)"
                                >
                                    Quote
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="decline(quotation)"
                                >
                                    Decline
                                </Button>
                            </div>
                        </div>

                        <!-- The reply: a price, a date it stands until, a delivery note. -->
                        <form
                            v-if="replyingTo === quotation.id"
                            class="bg-muted/40 grid gap-3 rounded-lg border p-3 sm:grid-cols-2"
                            @submit.prevent="submitQuote(quotation)"
                        >
                            <div class="space-y-1.5">
                                <Label :for="`price-${quotation.id}`">
                                    Your price each (K)
                                </Label>
                                <Input
                                    :id="`price-${quotation.id}`"
                                    v-model="form.unit_price"
                                    inputmode="decimal"
                                    placeholder="1250.00"
                                    required
                                />
                                <InputError :message="form.errors.unit_price" />
                            </div>

                            <div class="space-y-1.5">
                                <Label :for="`valid-${quotation.id}`">
                                    Valid until
                                </Label>
                                <Input
                                    :id="`valid-${quotation.id}`"
                                    v-model="form.valid_until"
                                    type="date"
                                    required
                                />
                                <InputError
                                    :message="form.errors.valid_until"
                                />
                            </div>

                            <div class="space-y-1.5 sm:col-span-2">
                                <Label :for="`delivery-${quotation.id}`">
                                    Delivery or collection note
                                    <span
                                        class="text-muted-foreground font-normal"
                                    >
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    :id="`delivery-${quotation.id}`"
                                    v-model="form.delivery_note"
                                    placeholder="Ready for collection in two working days."
                                />
                                <InputError
                                    :message="form.errors.delivery_note"
                                />
                            </div>

                            <div class="flex gap-2 sm:col-span-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="form.processing"
                                >
                                    Send quote
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="replyingTo = null"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </li>
        </ul>
    </div>
</template>
