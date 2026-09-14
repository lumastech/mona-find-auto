<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import sellers from '@/routes/sellers';
import type { SellerTypeCode, SellerTypeOption } from '@/types';

/**
 * Step one: what kind of business this is.
 *
 * The type decides more than a label — a car breaker's listings are badged
 * as breaker stock automatically, and a garage is asked questions a parts
 * retailer is not — so it is chosen deliberately from cards rather than
 * skimmed past in a dropdown.
 */
const props = defineProps<{
    types: SellerTypeOption[];
    selected: SellerTypeCode | null;
}>();

const chosen = ref<SellerTypeCode | null>(props.selected);
</script>

<template>
    <Form
        v-bind="sellers.register.store.form({ step: 'type' })"
        v-slot="{ errors, processing }"
        class="space-y-6"
    >
        <fieldset class="space-y-3">
            <legend class="sr-only">Business type</legend>

            <label
                v-for="option in types"
                :key="option.value"
                class="hover:bg-accent/50 flex cursor-pointer gap-3 rounded-lg border p-4 transition-colors"
                :class="
                    chosen === option.value
                        ? 'border-primary bg-primary/5'
                        : 'border-border'
                "
            >
                <input
                    v-model="chosen"
                    type="radio"
                    name="type"
                    :value="option.value"
                    class="mt-1 size-4 shrink-0"
                />
                <span class="min-w-0">
                    <span class="block font-medium">{{ option.label }}</span>
                    <span class="text-muted-foreground block text-sm">
                        {{ option.description }}
                    </span>
                </span>
            </label>
        </fieldset>

        <InputError :message="errors.type" />

        <Button type="submit" :disabled="processing || chosen === null">
            <Spinner v-if="processing" class="size-4" />
            Continue
        </Button>
    </Form>
</template>
