<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Minus, Plus, ShoppingCart } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import cart from '@/routes/cart';
import type { ProductVariant } from '@/types';

/**
 * Choose a quantity and put it in the cart.
 *
 * The stepper stops at what the seller says is on the shelf. That number is
 * the one place the storefront shows a raw count rather than "Low stock" —
 * a stepper that silently refuses to go past four is more confusing than one
 * that explains itself, and the buyer is about to commit to a quantity
 * anyway.
 *
 * A guest sees the same button; the post is guarded, so they are sent to log
 * in and returned to this listing.
 */
const props = defineProps<{ variant: ProductVariant }>();

const quantity = ref(1);

const max = computed(() => Math.max(1, props.variant.quantity));

const form = useForm({ variant_id: props.variant.id, quantity: 1 });

const step = (by: number): void => {
    quantity.value = Math.min(max.value, Math.max(1, quantity.value + by));
};

const submit = (): void => {
    form.variant_id = props.variant.id;
    form.quantity = quantity.value;

    form.post(cart.store().url, { preserveScroll: true });
};
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div
            v-if="variant.in_stock"
            class="flex items-center rounded-md border"
            role="group"
            aria-label="Quantity"
        >
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-9 rounded-r-none"
                :disabled="quantity <= 1"
                aria-label="One fewer"
                @click="step(-1)"
            >
                <Minus class="size-4" aria-hidden="true" />
            </Button>

            <span class="w-10 text-center text-sm tabular-nums">
                {{ quantity }}
            </span>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-9 rounded-l-none"
                :disabled="quantity >= max"
                aria-label="One more"
                @click="step(1)"
            >
                <Plus class="size-4" aria-hidden="true" />
            </Button>
        </div>

        <Button
            type="button"
            :disabled="!variant.in_stock || form.processing"
            @click="submit"
        >
            <ShoppingCart class="size-4" aria-hidden="true" />
            {{ variant.in_stock ? 'Add to cart' : 'Out of stock' }}
        </Button>

        <p
            v-if="variant.in_stock && quantity >= max"
            class="text-muted-foreground w-full text-xs"
        >
            {{ max }} is all this seller has.
        </p>
    </div>
</template>
