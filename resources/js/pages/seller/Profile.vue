<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import AddressFields from '@/components/identity/AddressFields.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import VerificationBadge from '@/components/storefront/VerificationBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import sellerRoutes from '@/routes/seller';
import type {
    ProvinceOption,
    SellerCommercialTerms,
    SellerProfile,
} from '@/types';

/**
 * A seller editing their own shop.
 *
 * The business type is not on this form: changing a shop into a car breaker
 * changes what every one of its listings is badged as, so that is a
 * conversation with MonaFind rather than a dropdown. Nor are the commercial
 * terms — those are shown so a seller can check their own payouts, and set by
 * MonaFind because they decide how much of a buyer's money reaches them.
 */
defineProps<{
    seller: SellerProfile & {
        registration_number: string | null;
        province_id: number;
        city_id: number;
        phone: string;
        email: string;
        contact_person: string;
    };
    commercialTerms: SellerCommercialTerms;
    provinces: ProvinceOption[];
    asksBayCount: boolean;
}>();
</script>

<template>
    <Head title="Shop details" />

    <div class="space-y-6 p-4">
        <Heading
            title="Shop details"
            description="What buyers see about your business."
        />

        <div class="flex flex-wrap items-center gap-2">
            <VerificationBadge
                :verified="seller.verified"
                :label="seller.verification_label"
            />
            <Badge variant="secondary">{{ seller.type_label }}</Badge>
        </div>

        <Form
            v-bind="sellerRoutes.profile.update.form()"
            v-slot="{ errors, processing }"
            class="max-w-3xl space-y-8"
        >
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="business_name">Business name</Label>
                    <Input
                        id="business_name"
                        name="business_name"
                        :default-value="seller.business_name"
                        required
                    />
                    <InputError :message="errors.business_name" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="registration_number">
                        PACRA registration number
                    </Label>
                    <Input
                        id="registration_number"
                        name="registration_number"
                        :default-value="seller.registration_number ?? ''"
                    />
                    <InputError :message="errors.registration_number" />
                </div>
            </div>

            <AddressFields
                :provinces="provinces"
                :errors="errors"
                :province-id="seller.province_id"
                :city-id="seller.city_id"
                :street="seller.location.street"
                :plot-number="seller.location.plot_number"
            />

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input
                        id="phone"
                        name="phone"
                        type="tel"
                        :default-value="seller.phone"
                        required
                    />
                    <InputError :message="errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        :default-value="seller.email"
                        required
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="contact_person">Contact person</Label>
                    <Input
                        id="contact_person"
                        name="contact_person"
                        :default-value="seller.contact_person"
                        required
                    />
                    <InputError :message="errors.contact_person" />
                </div>

                <div v-if="asksBayCount" class="grid gap-2">
                    <Label for="bay_count">Service bays on site</Label>
                    <Input
                        id="bay_count"
                        name="bay_count"
                        type="number"
                        min="1"
                        :default-value="seller.bay_count ?? ''"
                        required
                    />
                    <InputError :message="errors.bay_count" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="description">About the business</Label>
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    :value="seller.description ?? ''"
                    class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
                />
                <InputError :message="errors.description" />
            </div>

            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" class="size-4" />
                Save changes
            </Button>
        </Form>

        <Card class="max-w-3xl">
            <CardHeader>
                <CardTitle class="text-base"
                    >Your terms with MonaFind</CardTitle
                >
            </CardHeader>
            <CardContent class="space-y-4 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-muted-foreground">Payment mode</span>
                    <Badge variant="secondary">
                        {{ commercialTerms.payment_mode_label }}
                    </Badge>
                </div>

                <p class="text-muted-foreground">
                    {{ commercialTerms.payment_mode_description }}
                </p>

                <p
                    class="text-muted-foreground flex items-start gap-2 rounded-lg border p-3"
                >
                    <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>
                        MonaFind sets your payment mode and your commission.
                        Talk to support if you think yours is wrong.
                    </span>
                </p>
            </CardContent>
        </Card>
    </div>
</template>
