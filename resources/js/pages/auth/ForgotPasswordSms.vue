<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { request } from '@/routes/password';
import { send } from '@/routes/password/sms';

defineProps<{
    status?: string;
}>();

defineOptions({
    layout: {
        title: 'Reset your password by SMS',
        description: 'We will text a code to your registered mobile number',
    },
});
</script>

<template>
    <Head title="Reset your password by SMS" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600 dark:text-green-500"
    >
        {{ status }}
    </div>

    <Form
        v-bind="send.form()"
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
            <InputError :message="errors.phone" />
        </div>

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Text me a code
        </Button>

        <div class="text-muted-foreground space-x-1 text-center text-sm">
            <TextLink :href="request()">Reset by email instead</TextLink>
            <span>·</span>
            <TextLink :href="login()">Back to log in</TextLink>
        </div>
    </Form>
</template>
