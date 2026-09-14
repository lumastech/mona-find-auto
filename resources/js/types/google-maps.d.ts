/**
 * The slice of the Google Maps JS API the seller map picker actually touches.
 *
 * `@types/google.maps` is not a dependency of this project, and pulling in
 * the full SDK typings for one component would be a lot of surface for four
 * classes. Declaring what we use keeps the compiler honest about it: adding
 * a call the map picker does not already make means declaring it here first.
 */
declare namespace google.maps {
    type LatLngLiteral = { lat: number; lng: number };

    class LatLng {
        lat(): number;
        lng(): number;
    }

    type MapMouseEvent = { latLng: LatLng | null };

    type MapOptions = {
        center: LatLngLiteral;
        zoom: number;
        mapTypeControl?: boolean;
        streetViewControl?: boolean;
    };

    class Map {
        constructor(element: HTMLElement, options: MapOptions);
        panTo(position: LatLngLiteral): void;
        addListener(
            event: string,
            handler: (event: MapMouseEvent) => void,
        ): void;
    }

    type MarkerOptions = {
        map: Map;
        position: LatLngLiteral;
        draggable?: boolean;
    };

    class Marker {
        constructor(options: MarkerOptions);
        setPosition(position: LatLngLiteral): void;
        getPosition(): LatLng | undefined;
        addListener(event: string, handler: () => void): void;
    }

    type GeocoderResult = { formatted_address: string; place_id?: string };

    class Geocoder {
        geocode(
            request: { location: LatLngLiteral },
            callback: (
                results: GeocoderResult[] | null,
                status: string,
            ) => void,
        ): void;
    }
}

interface Window {
    google?: typeof google;
}
