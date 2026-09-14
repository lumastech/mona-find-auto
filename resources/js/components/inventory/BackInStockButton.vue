<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { BellRing, BellOff } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import stockAlerts from '@/routes/stock-alerts';
import { login } from '@/routes';
import type { ProductVariant } from '@/types';

/**
 * "Tell me when this is back."
 *
 * A guest sees the button and is sent to log in rather than being hidden from
 * the feature — the same shape as the blurred seller contact details on the
 * same page. There is nowhere to send a notification to somebody the platform
 * cannot identify, but that is a reason to ask them to sign in, not a reason
 * to pretend the option does not exist.
 */
const props = defineProps<{
    variant: ProductVariant;
    subscribed: boolean;
    isAuthenticated: boolean;
}>();

const form = useForm({});

const toggle = () => {
    const url = stockAlerts[props.subscribed ? 'destroy' : 'store'](
        props.variant.id,
    ).url;

    if (props.subscribed) {
        form.delete(url, { preserveScroll: true });

        return;
    }

    form.post(url, { preserveScroll: true });
};

const label = computed(() =>
    props.subscribed ? 'Stop notifying me' : 'Notify me when back in stock',
);
</script>

<template>
    <Button
        v-if="isAuthenticated"
        type="button"
        variant="outline"
        :disabled="form.processing"
        @click="toggle"
    >
        <component
            :is="subscribed ? BellOff : BellRing"
            class="size-4"
            aria-hidden="true"
        />
        {{ label }}
    </Button>

    <Button v-else variant="outline" as-child>
        <Link :href="login().url">
            <BellRing class="size-4" aria-hidden="true" />
            Log in to be notified
        </Link>
    </Button>
</template>
