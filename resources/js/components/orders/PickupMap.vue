<script setup lang="ts">
import { ExternalLink, MapPin } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * Where to go and collect the part.
 *
 * The embedded map needs a browser key and 300KB of Google's SDK; the
 * directions link needs neither and works on every phone that has a maps app
 * at all. Most MonaFind buyers are on a low-end Android on mobile data, so
 * the link is the primary control and the map is the enhancement — never the
 * other way round.
 */
const props = defineProps<{
    name: string;
    address: string;
    latitude: number | null;
    longitude: number | null;
    apiKey?: string | null;
}>();

const hasPin = computed(
    () => props.latitude !== null && props.longitude !== null,
);

/** Google's universal directions URL: it opens the app when there is one. */
const directionsUrl = computed(() => {
    const destination = hasPin.value
        ? `${props.latitude},${props.longitude}`
        : `${props.name} ${props.address}`;

    return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`;
});

const embedUrl = computed(() => {
    if (!props.apiKey || !hasPin.value) {
        return null;
    }

    return `https://www.google.com/maps/embed/v1/place?key=${props.apiKey}&q=${props.latitude},${props.longitude}&zoom=15`;
});
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-start gap-2 text-sm">
            <MapPin
                class="text-muted-foreground mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <div>
                <p class="font-medium">{{ name }}</p>
                <p class="text-muted-foreground">{{ address }}</p>
            </div>
        </div>

        <iframe
            v-if="embedUrl"
            :src="embedUrl"
            class="aspect-video w-full rounded-md border"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            :title="`Map showing ${name}`"
        />

        <Button as-child variant="outline" size="sm" class="w-full sm:w-auto">
            <a :href="directionsUrl" target="_blank" rel="noopener noreferrer">
                <ExternalLink class="size-4" aria-hidden="true" />
                Get directions
            </a>
        </Button>
    </div>
</template>
