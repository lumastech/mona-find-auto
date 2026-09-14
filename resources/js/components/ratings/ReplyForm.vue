<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import sellerRatings from '@/routes/seller/ratings';
import type { Rating } from '@/types';

/**
 * The seller's one reply.
 *
 * The form says it is the only one, because it is: there is no edit and no
 * second reply, and a seller who finds that out after posting "noted" has
 * been badly served by the interface.
 */
const props = defineProps<{ rating: Rating }>();

const form = useForm({ reply: '' });

const submit = (): void => {
    form.post(sellerRatings.reply(props.rating.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <form class="space-y-2" @submit.prevent="submit">
        <Label :for="`reply-${rating.id}`">
            Your reply — you get one, and it is public
        </Label>
        <textarea
            :id="`reply-${rating.id}`"
            v-model="form.reply"
            rows="3"
            maxlength="1000"
            class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
            placeholder="Answer the buyer. Other buyers read this."
        />
        <InputError :message="form.errors.reply" />
        <Button type="submit" size="sm" :disabled="form.processing">
            Post reply
        </Button>
    </form>
</template>
