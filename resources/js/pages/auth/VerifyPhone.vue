<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { resend } from '@/routes/phone';
import { submit } from '@/routes/phone/verify';

const props = defineProps<{
    phone: string | null;
    status?: string;
    resendAvailableIn: number;
    expiryMinutes: number;
}>();

defineOptions({
    layout: {
        title: 'Confirm your mobile number',
        description: 'We texted you a six-digit code',
    },
});

/**
 * Count the resend window down so the button says when it will work rather
 * than looking broken.
 */
const secondsLeft = ref(props.resendAvailableIn);

const timer = window.setInterval(() => {
    if (secondsLeft.value > 0) {
        secondsLeft.value -= 1;
    }
}, 1000);

onUnmounted(() => window.clearInterval(timer));

watch(
    () => props.resendAvailableIn,
    (value) => (secondsLeft.value = value),
);

const canResend = computed(() => secondsLeft.value <= 0);
</script>

<template>
    <Head title="Confirm your mobile number" />

    <div class="flex flex-col gap-6">
        <p class="text-muted-foreground text-sm">
            Enter the code we sent to
            <span class="text-foreground font-medium">{{
                phone ?? 'your phone'
            }}</span
            >. It expires in {{ expiryMinutes }} minutes.
        </p>

        <div
            v-if="status"
            class="text-sm font-medium text-green-600 dark:text-green-500"
        >
            {{ status }}
        </div>

        <Form
            v-bind="submit.form()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-2">
                <Label for="code">Verification code</Label>
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

            <Button type="submit" class="w-full" :disabled="processing">
                <Spinner v-if="processing" />
                Confirm number
            </Button>
        </Form>

        <Form v-bind="resend.form()" v-slot="{ processing }">
            <Button
                type="submit"
                variant="outline"
                class="w-full"
                :disabled="processing || !canResend"
            >
                <Spinner v-if="processing" />
                {{
                    canResend
                        ? 'Send another code'
                        : `Send another code in ${secondsLeft}s`
                }}
            </Button>
        </Form>

        <div class="text-muted-foreground text-center text-sm">
            Wrong number?
            <TextLink :href="logout()" method="post" as="button">
                Log out
            </TextLink>
            and register again.
        </div>
    </div>
</template>
