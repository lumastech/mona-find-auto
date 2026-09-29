<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import AddressFields from '@/components/identity/AddressFields.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/phone/setup';
import type { ProvinceOption } from '@/types';
import { computed } from 'vue';

/**
 * Where a Google or Facebook signup lands. Those give us a name and an email
 * address; MonaFind still needs a Zambian number to reach a buyer about an
 * order and an address to deliver to.
 */
defineProps<{
    provinces: ProvinceOption[];
}>();

const page = usePage();

/*
 * The number is collected either way; what we promise to do with it depends
 * on whether there is an SMS gateway to send a code through.
 */
const phoneHint = computed(() =>
    page.props.platform.phoneVerification
        ? 'We text a code here to confirm it is yours.'
        : 'We use this to reach you about your orders.',
);

/* Without a gateway this form sends nothing; it just saves the profile. */
const submitLabel = computed(() =>
    page.props.platform.phoneVerification
        ? 'Send me a code'
        : 'Save and continue',
);

defineOptions({
    layout: {
        title: 'Finish setting up your account',
        description:
            'We need a mobile number and an address before you can order',
    },
});
</script>

<template>
    <Head title="Finish setting up your account" />

    <Form
        v-bind="store.form()"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-2">
            <Label for="phone">Mobile number</Label>
            <Input
                id="phone"
                name="phone"
                type="tel"
                inputmode="tel"
                required
                autofocus
                autocomplete="tel"
                placeholder="0977 123 456"
            />
            <p class="text-muted-foreground text-xs">
                {{ phoneHint }}
            </p>
            <InputError :message="errors.phone" />
        </div>

        <AddressFields :provinces="provinces" :errors="errors" />

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            {{ submitLabel }}
        </Button>
    </Form>
</template>
