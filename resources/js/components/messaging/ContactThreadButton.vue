<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { MessageSquare } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import threads from '@/routes/threads';

/**
 * "Message the seller", from a listing, a quote or an order.
 *
 * Posts and lands in the conversation. It does not first ask whether one
 * exists, because opening is idempotent — a second press finds the first
 * thread rather than making another — which is what lets this be one button
 * on every page that needs it rather than a button and a lookup.
 */
const props = withDefaults(
    defineProps<{
        subjectType: 'listing' | 'shop' | 'quotation' | 'order';
        subjectId: number;
        label?: string;
        variant?: 'default' | 'secondary' | 'outline' | 'ghost';
    }>(),
    { label: 'Message the seller', variant: 'outline' },
);

const form = useForm({
    subject_type: props.subjectType,
    subject_id: props.subjectId,
    body: '',
});

const open = (): void => {
    form.post(threads.store.url(), { preserveScroll: true });
};
</script>

<template>
    <Button
        type="button"
        :variant="variant"
        :disabled="form.processing"
        class="gap-2"
        @click="open"
    >
        <MessageSquare class="size-4" />
        {{ label }}
    </Button>
</template>
