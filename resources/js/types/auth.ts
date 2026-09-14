import type { AccountStatus } from './identity';

export type User = {
    id: number;
    first_name: string;
    last_name: string;
    /** Kept in step with first_name and last_name server-side. */
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    /** E.164, e.g. "+260977123456". */
    phone: string | null;
    phone_network: 'mtn' | 'airtel' | 'zamtel' | null;
    phone_verified_at: string | null;
    status: AccountStatus;
    status_reason: string | null;
    province_id: number | null;
    city_id: number | null;
    street: string | null;
    plot_number: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
    /** Role names held by the current account, e.g. ['buyer', 'seller']. */
    roles: string[];
    /** May open the /seller portal. */
    isSeller: boolean;
    /** May open the /admin console. */
    isStaff: boolean;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
