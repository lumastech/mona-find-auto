<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { CheckCheck, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import sellerStock from '@/routes/seller/stock';
import type { StockSummary } from '@/types';

/**
 * The one-tap "All stock accurate" prompt.
 *
 * The entire freshness scheme rests on this button being one tap. A seller
 * asked to tick four hundred boxes stops confirming by the second week, every
 * listing on the platform slides to Unconfirmed, and the label stops meaning
 * anything to buyers — so the whole shop is confirmed from here, and the
 * per-listing corrections live on the stock screen for the seller who
 * genuinely wants them.
 *
 * It says nothing at all when there is nothing to say. A banner that is
 * always present is a banner nobody reads.
 */
const props = defineProps<{ summary: StockSummary }>();

const form = useForm({});

const confirmAll = () =>
    form.post(sellerStock.confirm().url, { preserveScroll: true });

/** Hidden listings are the urgent case: those are off the storefront now. */
const isUrgent = computed(
    () => props.summary.hidden > 0 || props.summary.unconfirmed > 0,
);

const headline = computed(() => {
    if (props.summary.hidden > 0) {
        return `${props.summary.hidden} ${props.summary.hidden === 1 ? 'listing is' : 'listings are'} hidden from buyers`;
    }

    if (props.summary.unconfirmed > 0) {
        return `${props.summary.unconfirmed} ${props.summary.unconfirmed === 1 ? 'listing is' : 'listings are'} marked "stock unconfirmed"`;
    }

    return `${props.summary.ageing} ${props.summary.ageing === 1 ? 'listing needs' : 'listings need'} confirming`;
});

const explanation = computed(() => {
    if (props.summary.hidden > 0) {
        return 'Buyers cannot see them until you confirm your stock. One tap brings them all back.';
    }

    if (props.summary.unconfirmed > 0) {
        return 'Buyers see a warning on these, and they rank below shops that have confirmed. One tap clears it.';
    }

    return 'Confirming every few days keeps your listings ranking well.';
});
</script>

<template>
    <Alert
        v-if="summary.needs_confirmation > 0"
        :variant="isUrgent ? 'destructive' : 'default'"
        data-slot="stock-confirmation-banner"
    >
        <TriangleAlert class="size-4" aria-hidden="true" />
        <AlertTitle>{{ headline }}</AlertTitle>
        <AlertDescription class="space-y-3">
            <p>{{ explanation }}</p>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    :disabled="form.processing"
                    @click="confirmAll"
                >
                    <CheckCheck class="size-4" aria-hidden="true" />
                    All stock accurate
                </Button>

                <Button size="sm" variant="outline" as-child>
                    <Link :href="sellerStock.index().url">
                        Review listing by listing
                    </Link>
                </Button>
            </div>
        </AlertDescription>
    </Alert>
</template>
