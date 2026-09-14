<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AddressFields from '@/components/identity/AddressFields.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import addressRoutes from '@/routes/addresses';
import type { DeliveryAddress, ProvinceOption } from '@/types';

const props = defineProps<{
    addresses: DeliveryAddress[];
    provinces: ProvinceOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Delivery addresses', href: addressRoutes.index() },
        ],
    },
});

/** Null while adding; an address while editing one. */
const editing = ref<DeliveryAddress | null>(null);
const showForm = ref(props.addresses.length === 0);

/**
 * An optional GPS pin for the address.
 *
 * The browser's own geolocation is enough here — the server turns the point
 * into a written address through MapsProvider, so the page never loads the
 * Google Maps SDK. That matters on the low-end Android phones most buyers
 * are on.
 */
const latitude = ref<number | null>(null);
const longitude = ref<number | null>(null);
const pinState = ref<'idle' | 'locating' | 'failed'>('idle');

function dropPin(): void {
    if (!navigator.geolocation) {
        pinState.value = 'failed';

        return;
    }

    pinState.value = 'locating';

    navigator.geolocation.getCurrentPosition(
        (position) => {
            latitude.value = position.coords.latitude;
            longitude.value = position.coords.longitude;
            pinState.value = 'idle';
        },
        () => (pinState.value = 'failed'),
        { enableHighAccuracy: true, timeout: 10000 },
    );
}

function clearPin(): void {
    latitude.value = null;
    longitude.value = null;
    pinState.value = 'idle';
}

function startAdding(): void {
    editing.value = null;
    clearPin();
    showForm.value = true;
}

function startEditing(address: DeliveryAddress): void {
    editing.value = address;
    latitude.value = address.latitude;
    longitude.value = address.longitude;
    pinState.value = 'idle';
    showForm.value = true;
}

function closeForm(): void {
    editing.value = null;
    showForm.value = false;
    clearPin();
}
</script>

<template>
    <Head title="Delivery addresses" />

    <h1 class="sr-only">Delivery addresses</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Delivery addresses"
            description="Where we send the parts you order. Add the workshop as well as home."
        />

        <div v-if="addresses.length" class="grid gap-4">
            <Card v-for="address in addresses" :key="address.id">
                <CardHeader>
                    <CardTitle class="flex flex-wrap items-center gap-2">
                        {{ address.label }}
                        <Badge v-if="address.is_default" variant="secondary">
                            Default
                        </Badge>
                    </CardTitle>
                </CardHeader>

                <CardContent class="space-y-3 text-sm">
                    <div class="text-muted-foreground space-y-1">
                        <p class="text-foreground">
                            {{ address.recipient_name }}
                        </p>
                        <p>{{ address.recipient_phone }}</p>
                        <p>{{ address.single_line }}</p>
                        <p v-if="address.directions" class="italic">
                            {{ address.directions }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="startEditing(address)"
                        >
                            Edit
                        </Button>

                        <Button
                            v-if="!address.is_default"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link
                                :href="addressRoutes.default(address.id)"
                                method="put"
                                as="button"
                            >
                                Make default
                            </Link>
                        </Button>

                        <Button variant="ghost" size="sm" as-child>
                            <Link
                                :href="addressRoutes.destroy(address.id)"
                                method="delete"
                                as="button"
                                class="text-destructive"
                            >
                                Remove
                            </Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <p v-else class="text-muted-foreground text-sm">
            You have not saved a delivery address yet.
        </p>

        <Button v-if="!showForm" variant="outline" @click="startAdding">
            Add an address
        </Button>

        <Card v-if="showForm">
            <CardHeader>
                <CardTitle>
                    {{ editing ? `Edit ${editing.label}` : 'Add an address' }}
                </CardTitle>
            </CardHeader>

            <CardContent>
                <Form
                    :key="editing?.id ?? 'new'"
                    v-bind="
                        editing
                            ? addressRoutes.update.form(editing.id)
                            : addressRoutes.store.form()
                    "
                    v-slot="{ errors, processing }"
                    :reset-on-success="!editing"
                    class="flex flex-col gap-6"
                    @success="closeForm"
                >
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="label">Label</Label>
                            <Input
                                id="label"
                                name="label"
                                required
                                :default-value="editing?.label ?? ''"
                                placeholder="Home, Workshop, Mum"
                            />
                            <InputError :message="errors.label" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="recipient_name">Who receives it</Label>
                            <Input
                                id="recipient_name"
                                name="recipient_name"
                                required
                                :default-value="editing?.recipient_name ?? ''"
                                autocomplete="name"
                            />
                            <InputError :message="errors.recipient_name" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="recipient_phone"
                                >Their mobile number</Label
                            >
                            <Input
                                id="recipient_phone"
                                name="recipient_phone"
                                type="tel"
                                inputmode="tel"
                                required
                                :default-value="editing?.recipient_phone ?? ''"
                                placeholder="0977 123 456"
                            />
                            <InputError :message="errors.recipient_phone" />
                        </div>
                    </div>

                    <AddressFields
                        :provinces="provinces"
                        :errors="errors"
                        :province-id="editing?.province_id"
                        :city-id="editing?.city_id"
                        :street="editing?.street"
                        :plot-number="editing?.plot_number"
                    />

                    <div class="grid gap-2">
                        <Label for="directions">
                            Directions for the courier
                            <span class="text-muted-foreground font-normal">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="directions"
                            name="directions"
                            :default-value="editing?.directions ?? ''"
                            placeholder="Blue gate opposite the filling station"
                        />
                        <InputError :message="errors.directions" />
                    </div>

                    <div class="grid gap-2">
                        <span class="text-sm font-medium">
                            Map pin
                            <span class="text-muted-foreground font-normal">
                                (optional)
                            </span>
                        </span>

                        <input
                            type="hidden"
                            name="latitude"
                            :value="latitude ?? ''"
                        />
                        <input
                            type="hidden"
                            name="longitude"
                            :value="longitude ?? ''"
                        />

                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="pinState === 'locating'"
                                @click="dropPin"
                            >
                                <Spinner v-if="pinState === 'locating'" />
                                {{
                                    latitude === null
                                        ? 'Use my current location'
                                        : 'Update pin'
                                }}
                            </Button>

                            <Button
                                v-if="latitude !== null"
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="clearPin"
                            >
                                Remove pin
                            </Button>
                        </div>

                        <p
                            v-if="latitude !== null && longitude !== null"
                            class="text-muted-foreground text-xs"
                        >
                            Pinned at {{ latitude.toFixed(5) }},
                            {{ longitude.toFixed(5) }} — couriers will use this.
                        </p>
                        <p
                            v-else-if="pinState === 'failed'"
                            class="text-muted-foreground text-xs"
                        >
                            We could not get your location. The written address
                            is enough on its own.
                        </p>

                        <InputError
                            :message="errors.latitude ?? errors.longitude"
                        />
                    </div>

                    <Label for="is_default" class="flex items-center gap-3">
                        <input
                            id="is_default"
                            type="checkbox"
                            name="is_default"
                            value="1"
                            :checked="editing?.is_default"
                            class="border-input size-4 rounded border"
                        />
                        <span>Make this my default delivery address</span>
                    </Label>

                    <div class="flex items-center gap-3">
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            {{ editing ? 'Save changes' : 'Save address' }}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            @click="closeForm"
                        >
                            Cancel
                        </Button>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
