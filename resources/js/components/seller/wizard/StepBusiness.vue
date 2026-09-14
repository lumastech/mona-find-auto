<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import AddressFields from '@/components/identity/AddressFields.vue';
import InputError from '@/components/InputError.vue';
import MapPinPicker from '@/components/seller/MapPinPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import sellers from '@/routes/sellers';
import type { ProvinceOption } from '@/types';

/**
 * Step two: who the business is and where it trades from.
 *
 * The registration number is asked for but not required. Plenty of real shops
 * start selling before their PACRA certificate arrives, and turning them away
 * at sign-up loses the seller rather than protecting the buyer — the number
 * becomes mandatory at the Verified badge instead, which is the point where it
 * actually means something.
 */
const props = defineProps<{
    provinces: ProvinceOption[];
    answers: Record<string, unknown>;
    asksBayCount: boolean;
    mapsApiKey?: string | null;
}>();

const value = <T>(key: string): T | null => (props.answers[key] as T) ?? null;

const pin = ref({
    latitude: value<number>('latitude'),
    longitude: value<number>('longitude'),
    formatted_address: value<string>('formatted_address'),
    place_id: value<string>('place_id'),
});
</script>

<template>
    <Form
        v-bind="sellers.register.store.form({ step: 'business' })"
        v-slot="{ errors, processing }"
        class="space-y-8"
    >
        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2 sm:col-span-2">
                <Label for="business_name">Business name</Label>
                <Input
                    id="business_name"
                    name="business_name"
                    :default-value="value<string>('business_name') ?? ''"
                    required
                    autocomplete="organization"
                    placeholder="e.g. Kabwata Motor Spares"
                />
                <InputError :message="errors.business_name" />
            </div>

            <div class="grid gap-2 sm:col-span-2">
                <Label for="registration_number">
                    PACRA registration number
                    <span class="text-muted-foreground font-normal">
                        (optional for now)
                    </span>
                </Label>
                <Input
                    id="registration_number"
                    name="registration_number"
                    :default-value="value<string>('registration_number') ?? ''"
                    placeholder="e.g. 120210001234"
                />
                <p class="text-muted-foreground text-sm">
                    You can sell without it, but we cannot give you the Verified
                    badge until you add it.
                </p>
                <InputError :message="errors.registration_number" />
            </div>
        </div>

        <div class="space-y-6">
            <h2 class="text-sm font-medium">Where you trade from</h2>

            <AddressFields
                :provinces="provinces"
                :errors="errors"
                :province-id="value<number>('province_id')"
                :city-id="value<number>('city_id')"
                :street="value<string>('street')"
                :plot-number="value<string>('plot_number')"
            />

            <MapPinPicker
                :latitude="pin.latitude"
                :longitude="pin.longitude"
                :formatted-address="pin.formatted_address"
                :api-key="mapsApiKey"
                @update="pin = $event"
            />
        </div>

        <div class="space-y-6">
            <h2 class="text-sm font-medium">How buyers reach you</h2>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input
                        id="phone"
                        name="phone"
                        type="tel"
                        :default-value="value<string>('phone') ?? ''"
                        required
                        autocomplete="tel"
                        placeholder="0977 123 456"
                    />
                    <InputError :message="errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        :default-value="value<string>('email') ?? ''"
                        required
                        autocomplete="email"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="contact_person">Contact person</Label>
                    <Input
                        id="contact_person"
                        name="contact_person"
                        :default-value="value<string>('contact_person') ?? ''"
                        required
                        placeholder="Who buyers should ask for"
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
                        :default-value="value<number>('bay_count') ?? ''"
                        required
                    />
                    <InputError :message="errors.bay_count" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="description">
                    About the business
                    <span class="text-muted-foreground font-normal">
                        (optional)
                    </span>
                </Label>
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    :value="value<string>('description') ?? ''"
                    class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
                    placeholder="What you stock, which makes you specialise in, how long you have been trading."
                />
                <InputError :message="errors.description" />
            </div>
        </div>

        <Button type="submit" :disabled="processing">
            <Spinner v-if="processing" class="size-4" />
            Save and continue
        </Button>
    </Form>
</template>
