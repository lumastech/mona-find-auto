/**
 * Shapes owned by the Identity module: places, addresses and devices.
 */

export type CityOption = {
    id: number;
    name: string;
    is_major: boolean;
};

export type ProvinceOption = {
    id: number;
    name: string;
    cities: CityOption[];
};

export type DeliveryAddress = {
    id: number;
    label: string;
    recipient_name: string;
    recipient_phone: string;
    province_id: number;
    province?: string;
    city_id: number;
    city?: string;
    street: string;
    plot_number: string | null;
    directions: string | null;
    latitude: number | null;
    longitude: number | null;
    formatted_address: string | null;
    is_default: boolean;
    single_line: string;
};

export type BrowserSession = {
    id: string;
    ip_address: string | null;
    device: string;
    platform: string | null;
    browser: string | null;
    last_active: string;
    last_active_at: string;
    is_current: boolean;
};

export type ApiToken = {
    id: number;
    name: string;
    last_used: string | null;
    created_at: string | null;
};

export type SocialProviderOption = {
    value: string;
    label: string;
};

export type AccountStatus = 'pending' | 'active' | 'suspended' | 'closed';
