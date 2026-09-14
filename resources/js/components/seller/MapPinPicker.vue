<script setup lang="ts">
import { LocateFixed, MapPin, X } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/**
 * The seller's map pin, with the written address filled in from it.
 *
 * Google Maps is loaded lazily and only when a browser key is configured.
 * Without one — every test run, and any environment where the key has not
 * been set — this falls back to the browser's own geolocation, which is
 * enough to drop a pin and have the server reverse-geocode it. Most Zambian
 * sellers are on a low-end Android phone, so a screen that cannot work
 * without a 300KB map SDK is a screen that does not work.
 */
const props = defineProps<{
    latitude: number | null;
    longitude: number | null;
    formattedAddress: string | null;
    apiKey?: string | null;
}>();

const emit = defineEmits<{
    (
        event: 'update',
        value: {
            latitude: number | null;
            longitude: number | null;
            formatted_address: string | null;
            place_id: string | null;
        },
    ): void;
}>();

const latitude = ref(props.latitude);
const longitude = ref(props.longitude);
const address = ref(props.formattedAddress);
const state = ref<'idle' | 'loading' | 'locating' | 'failed'>('idle');
const canvas = ref<HTMLDivElement | null>(null);

/** Cairo Road, Lusaka — where the map opens when there is no pin yet. */
const LUSAKA = { lat: -15.4167, lng: 28.2833 };

let map: google.maps.Map | null = null;
let marker: google.maps.Marker | null = null;
let geocoder: google.maps.Geocoder | null = null;

function publish(): void {
    emit('update', {
        latitude: latitude.value,
        longitude: longitude.value,
        formatted_address: address.value,
        place_id: null,
    });
}

function setPin(lat: number, lng: number): void {
    latitude.value = Number(lat.toFixed(6));
    longitude.value = Number(lng.toFixed(6));

    marker?.setPosition({ lat, lng });
    map?.panTo({ lat, lng });

    /* Prefill the written address from the pin, so nobody types it twice. */
    geocoder?.geocode({ location: { lat, lng } }, (results, status) => {
        if (status === 'OK' && results?.[0]) {
            address.value = results[0].formatted_address;
        }

        publish();
    });

    if (!geocoder) {
        publish();
    }
}

function clearPin(): void {
    latitude.value = null;
    longitude.value = null;
    address.value = null;
    marker?.setPosition(LUSAKA);
    publish();
}

/**
 * The fallback everywhere the Maps SDK is not available: the phone knows
 * where it is, and the server turns that into an address.
 */
function useMyLocation(): void {
    if (!navigator.geolocation) {
        state.value = 'failed';

        return;
    }

    state.value = 'locating';

    navigator.geolocation.getCurrentPosition(
        (position) => {
            setPin(position.coords.latitude, position.coords.longitude);
            state.value = 'idle';
        },
        () => (state.value = 'failed'),
        { enableHighAccuracy: true, timeout: 10000 },
    );
}

/**
 * Load the Maps SDK once per page. Resolves false when there is no key, which
 * is the signal to stay on the geolocation fallback.
 */
function loadMaps(key: string): Promise<boolean> {
    if (window.google?.maps) {
        return Promise.resolve(true);
    }

    return new Promise((resolve) => {
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${key}&region=ZM&language=en`;
        script.async = true;
        script.onload = () => resolve(true);
        script.onerror = () => resolve(false);
        document.head.appendChild(script);
    });
}

onMounted(async () => {
    if (!props.apiKey || !canvas.value) {
        return;
    }

    state.value = 'loading';

    if (!(await loadMaps(props.apiKey)) || !canvas.value) {
        state.value = 'idle';

        return;
    }

    const centre =
        latitude.value !== null && longitude.value !== null
            ? { lat: latitude.value, lng: longitude.value }
            : LUSAKA;

    map = new google.maps.Map(canvas.value, {
        center: centre,
        zoom: latitude.value === null ? 12 : 16,
        mapTypeControl: false,
        streetViewControl: false,
    });

    marker = new google.maps.Marker({
        map,
        position: centre,
        draggable: true,
    });

    geocoder = new google.maps.Geocoder();

    map.addListener('click', (event: google.maps.MapMouseEvent) => {
        if (event.latLng) {
            setPin(event.latLng.lat(), event.latLng.lng());
        }
    });

    marker.addListener('dragend', () => {
        const position = marker?.getPosition();

        if (position) {
            setPin(position.lat(), position.lng());
        }
    });

    state.value = 'idle';
});

onBeforeUnmount(() => {
    map = null;
    marker = null;
    geocoder = null;
});

watch(
    () => props.formattedAddress,
    (value) => (address.value = value),
);
</script>

<template>
    <div class="space-y-3">
        <div
            v-if="apiKey"
            ref="canvas"
            class="bg-muted h-56 w-full overflow-hidden rounded-lg border sm:h-72"
            role="application"
            aria-label="Drop a pin on your business"
        />

        <div class="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="state === 'locating'"
                @click="useMyLocation"
            >
                <Spinner v-if="state === 'locating'" class="size-4" />
                <LocateFixed v-else class="size-4" aria-hidden="true" />
                Use my current location
            </Button>

            <Button
                v-if="latitude !== null"
                type="button"
                variant="ghost"
                size="sm"
                @click="clearPin"
            >
                <X class="size-4" aria-hidden="true" />
                Remove pin
            </Button>
        </div>

        <p
            v-if="latitude !== null"
            class="text-muted-foreground flex items-start gap-2 text-sm"
        >
            <MapPin class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                {{ address ?? `${latitude}, ${longitude}` }}
            </span>
        </p>

        <p v-else-if="state === 'failed'" class="text-destructive text-sm">
            We could not read your location. Type your address above instead —
            the pin is optional.
        </p>

        <p v-else class="text-muted-foreground text-sm">
            A pin helps buyers find you and lets couriers quote a delivery. It
            is optional.
        </p>

        <input type="hidden" name="latitude" :value="latitude ?? ''" />
        <input type="hidden" name="longitude" :value="longitude ?? ''" />
        <input type="hidden" name="formatted_address" :value="address ?? ''" />
    </div>
</template>
