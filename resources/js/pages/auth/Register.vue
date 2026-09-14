<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import CaptchaField from '@/components/CaptchaField.vue';
import AddressFields from '@/components/identity/AddressFields.vue';
import SocialAuthButtons from '@/components/identity/SocialAuthButtons.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { show as showPage } from '@/routes/pages';
import { store } from '@/routes/register';
import type { ProvinceOption, SocialProviderOption } from '@/types';

defineProps<{
    passwordRules: string;
    socialProviders: SocialProviderOption[];
    provinces: ProvinceOption[];
}>();

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Buy and sell vehicle parts across Zambia',
    },
});
</script>

<template>
    <Head title="Register" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <SocialAuthButtons :providers="socialProviders" action="Sign up" />

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="first_name">First name</Label>
                <Input
                    id="first_name"
                    name="first_name"
                    type="text"
                    required
                    autofocus
                    autocomplete="given-name"
                    placeholder="Chanda"
                />
                <InputError :message="errors.first_name" />
            </div>

            <div class="grid gap-2">
                <Label for="last_name">Last name</Label>
                <Input
                    id="last_name"
                    name="last_name"
                    type="text"
                    required
                    autocomplete="family-name"
                    placeholder="Mwale"
                />
                <InputError :message="errors.last_name" />
            </div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="you@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="phone">Mobile number</Label>
                <Input
                    id="phone"
                    name="phone"
                    type="tel"
                    inputmode="tel"
                    required
                    autocomplete="tel"
                    placeholder="0977 123 456"
                />
                <p class="text-muted-foreground text-xs">
                    We text a code here to confirm it is yours.
                </p>
                <InputError :message="errors.phone" />
            </div>
        </div>

        <AddressFields :provinces="provinces" :errors="errors" />

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="Password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>
        </div>

        <!--
            Three separate agreements, not one.

            Bundling the terms, the privacy notice and the marketing opt-in
            into a single tickbox is one of the things that makes consent not
            freely given under the Data Protection Act 2021 — so the two that
            are required are asked for separately from the one that is not,
            and each is recorded against the version of the document linked
            here. See App\Modules\Privacy\Services\ConsentRecorder.
        -->
        <fieldset class="grid gap-3">
            <legend class="sr-only">Agreements</legend>

            <div class="flex items-start gap-3">
                <Checkbox id="accept_terms" name="accept_terms" value="1" />
                <Label
                    for="accept_terms"
                    class="text-sm leading-snug font-normal"
                >
                    I accept the
                    <TextLink
                        :href="showPage('platform-terms')"
                        class="underline underline-offset-4"
                    >
                        terms of service </TextLink
                    >.
                </Label>
            </div>
            <InputError :message="errors.accept_terms" />

            <div class="flex items-start gap-3">
                <Checkbox id="accept_privacy" name="accept_privacy" value="1" />
                <Label
                    for="accept_privacy"
                    class="text-sm leading-snug font-normal"
                >
                    I have read the
                    <TextLink
                        :href="showPage('privacy')"
                        class="underline underline-offset-4"
                    >
                        privacy notice </TextLink
                    >, which explains how MonaFind uses my personal data.
                </Label>
            </div>
            <InputError :message="errors.accept_privacy" />

            <div class="flex items-start gap-3">
                <Checkbox
                    id="accept_marketing"
                    name="accept_marketing"
                    value="1"
                />
                <Label
                    for="accept_marketing"
                    class="text-sm leading-snug font-normal"
                >
                    Send me offers and news by SMS and email. (Optional — you
                    will always get order and security messages.)
                </Label>
            </div>
        </fieldset>

        <CaptchaField form="register" :error="errors.captcha_token" />

        <Button
            type="submit"
            class="w-full"
            :disabled="processing"
            data-test="register-user-button"
        >
            <Spinner v-if="processing" />
            Create account
        </Button>

        <div class="text-muted-foreground text-center text-sm">
            Already have an account?
            <TextLink :href="login()" class="underline underline-offset-4">
                Log in
            </TextLink>
        </div>
    </Form>
</template>
