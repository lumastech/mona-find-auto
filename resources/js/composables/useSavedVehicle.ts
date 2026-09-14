import { computed, ref, type ComputedRef, type Ref } from 'vue';
import type { SavedVehicle } from '@/types';

/**
 * The buyer's vehicle, remembered on their device.
 *
 * One vehicle, not a garage of them. A Zambian driver shopping for parts is
 * almost always shopping for the car outside, and a picker that asks "which
 * of your four vehicles?" before it will do anything is a picker that gets
 * abandoned. If a buyer has two cars they change the selection, which costs
 * three taps.
 *
 * localStorage rather than the session, and deliberately not the database:
 * this is a convenience, it belongs to the device, and it must keep working
 * for the guests who make up most of the traffic. Nothing here is personal
 * data the platform holds — see the Privacy module for the things that are.
 *
 * Read lazily through `hydrate()` rather than at module scope, because the
 * storefront is server-rendered: reading storage while building the initial
 * markup is impossible, and filling the ref during setup on the client would
 * hand Vue different HTML than the server sent.
 */

const STORAGE_KEY = 'mfa.vehicle';

const vehicle = ref<SavedVehicle | null>(null);

let hydrated = false;

export type UseSavedVehicleReturn = {
    vehicle: Ref<SavedVehicle | null>;
    /** "2014 Toyota Hilux", or null when nothing is remembered. */
    label: ComputedRef<string | null>;
    /** Call from onMounted; safe to call from every component that needs it. */
    hydrate: () => void;
    remember: (next: SavedVehicle) => void;
    forget: () => void;
};

export function useSavedVehicle(): UseSavedVehicleReturn {
    const hydrate = (): void => {
        if (hydrated) {
            return;
        }

        hydrated = true;
        vehicle.value = readStored();
    };

    const remember = (next: SavedVehicle): void => {
        vehicle.value = next;
        persist();
    };

    const forget = (): void => {
        vehicle.value = null;
        persist();
    };

    const label = computed(() => {
        const saved = vehicle.value;

        if (saved === null) {
            return null;
        }

        return [saved.year, saved.make_name, saved.vehicle_model_name]
            .filter((part): part is string | number => Boolean(part))
            .join(' ');
    });

    return { vehicle, label, hydrate, remember, forget };
}

/**
 * Storage is absent during SSR and can throw in a locked-down browser, so
 * every path here ends in "no vehicle remembered" rather than an exception.
 */
function readStored(): SavedVehicle | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);

        if (raw === null) {
            return null;
        }

        const parsed: unknown = JSON.parse(raw);

        if (typeof parsed !== 'object' || parsed === null) {
            return null;
        }

        const candidate = parsed as SavedVehicle;

        return typeof candidate.make_id === 'number' ? candidate : null;
    } catch {
        return null;
    }
}

function persist(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        if (vehicle.value === null) {
            window.localStorage.removeItem(STORAGE_KEY);

            return;
        }

        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(vehicle.value));
    } catch {
        /* A full or disabled store is not a reason to break the page. */
    }
}
