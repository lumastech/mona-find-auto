<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    defaultCurrency,
    formatMoney,
    ngweeOf,
    toDecimalString,
    type MoneyPayload,
} from '@/lib/money';

/**
 * Renders a ZMW amount held in integer ngwee.
 *
 * <Money :amount="129905" />          → K 1,299.05
 * <Money :amount="order.total" />     → accepts the Money envelope too
 */
const {
    amount,
    withSymbol = true,
    signed = false,
} = defineProps<{
    /** The amount, as ngwee or as a serialised Money object. */
    amount: MoneyPayload;
    /** Show the currency symbol. */
    withSymbol?: boolean;
    /** Prefix positive amounts with a "+", for ledger and adjustment views. */
    signed?: boolean;
}>();

const page = usePage();

const currency = computed(
    () => page.props.platform?.currency ?? defaultCurrency,
);

const ngwee = computed(() => ngweeOf(amount));

const formatted = computed(() => {
    const value = formatMoney(ngwee.value, currency.value, { withSymbol });

    return signed && ngwee.value > 0 ? `+${value}` : value;
});
</script>

<template>
    <span
        class="whitespace-nowrap tabular-nums"
        :class="{
            'text-destructive': signed && ngwee < 0,
            'text-emerald-600 dark:text-emerald-400': signed && ngwee > 0,
        }"
        :data-ngwee="ngwee"
        :title="`${currency.code} ${toDecimalString(ngwee, currency)}`"
    >
        {{ formatted }}
    </span>
</template>
