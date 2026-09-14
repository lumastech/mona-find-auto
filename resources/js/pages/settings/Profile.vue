<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import AddressFields from '@/components/identity/AddressFields.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAuthenticatedUser } from '@/composables/useAuthenticatedUser';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { ProvinceOption } from '@/types';

defineProps<{
    provinces: ProvinceOption[];
    mustVerifyEmail: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = useAuthenticatedUser();
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name, contact details and address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="first_name">First name</Label>
                    <Input
                        id="first_name"
                        name="first_name"
                        :default-value="user.first_name"
                        required
                        autocomplete="given-name"
                    />
                    <InputError :message="errors.first_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="last_name">Last name</Label>
                    <Input
                        id="last_name"
                        name="last_name"
                        :default-value="user.last_name"
                        required
                        autocomplete="family-name"
                    />
                    <InputError :message="errors.last_name" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="phone">Mobile number</Label>
                <Input
                    id="phone"
                    name="phone"
                    type="tel"
                    inputmode="tel"
                    :default-value="user.phone ?? ''"
                    required
                    autocomplete="tel"
                    placeholder="0977 123 456"
                />
                <p
                    v-if="!user.phone_verified_at"
                    class="text-muted-foreground text-xs"
                >
                    This number is not confirmed yet.
                </p>
                <InputError :message="errors.phone" />
            </div>

            <AddressFields
                :provinces="provinces"
                :errors="errors"
                :province-id="user.province_id"
                :city-id="user.city_id"
                :street="user.street"
                :plot-number="user.plot_number"
                :required="false"
            />

            <div v-if="mustVerifyEmail && !user.email_verified_at">
                <p class="text-muted-foreground -mt-4 text-sm">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
