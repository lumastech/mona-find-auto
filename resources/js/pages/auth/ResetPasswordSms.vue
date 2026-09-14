<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password/sms';

defineProps<{
    phone: string | null;
    status?: string;
}>();

defineOptions({
    layout: {
        title: 'Choose a new password',
        description: 'Enter the code we texted you and a new password',
    },
});
</script>

<template>
    <Head title="Choose a new password" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600 dark:text-green-500"
    >
        {{ status }}
    </div>

    <Form
        v-bind="update.form()"
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
                :default-value="phone ?? ''"
                :readonly="Boolean(phone)"
                autocomplete="tel"
            />
            <InputError :message="errors.phone" />
        </div>

        <div class="grid gap-2">
            <Label for="code">Reset code</Label>
            <Input
                id="code"
                name="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                required
                autofocus
                placeholder="123456"
                class="text-center text-lg tracking-[0.5em]"
            />
            <InputError :message="errors.code" />
        </div>

        <div class="grid gap-2">
            <Label for="password">New password</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="new-password"
                placeholder="New password"
            />
            <InputError :message="errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">Confirm new password</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
                placeholder="Confirm new password"
            />
            <InputError :message="errors.password_confirmation" />
        </div>

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Set new password
        </Button>
    </Form>
</template>
