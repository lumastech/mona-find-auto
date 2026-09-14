/**
 * The staff console's own shapes.
 *
 * Deliberately structural rather than per-module: the dashboard renders
 * counters contributed by eight different modules and the reference console
 * renders six lists owned by three, and neither screen knows — or should know
 * — what a payout batch or a vehicle make actually is.
 */

/** One piece of work waiting, and where to go to clear it. */
export type ConsoleCounter = {
    key: string;
    label: string;
    value: number;
    href: string;
    tone: 'neutral' | 'warning' | 'critical';
    hint: string | null;
};

export type ConsoleShortcut = {
    title: string;
    href: string;
    description: string;
};

/** How a settings field is rendered and what it will accept. */
export type SettingControl =
    | 'integer'
    | 'decimal'
    | 'money'
    | 'boolean'
    | 'select'
    | 'text'
    | 'day-list';

/**
 * What a setting can hold once it reaches the browser.
 *
 * Money arrives as a whole number of ngwee and a decimal as the string the
 * server stores — never a float, so nothing here can round a commission rate
 * on its way through a form.
 */
export type SettingValue = string | number | boolean | number[];

export type SettingField = {
    key: string;
    label: string;
    help: string | null;
    control: SettingControl;
    options: { value: string; label: string }[] | null;
    min: number | null;
    max: number | null;
    suffix: string | null;
    read_only: boolean;
    value: SettingValue;
};

export type SettingPanel = {
    key: string;
    title: string;
    description: string;
    fields: SettingField[];
};

/** One curated list in the consolidated reference console. */
export type ReferenceListSummary = {
    key: string;
    label: string;
    singular: string;
    owner: string;
    note: string | null;
    retirable: boolean;
    scoped: boolean;
    count: number;
};

export type ReferenceRow = {
    id: number;
    name: string;
    is_active: boolean | null;
    parent_id: number | null;
    /** How many rows across the whole platform point at this one. */
    usage: number;
};

export type ContentPageSummary = {
    id: number;
    slug: string;
    title: string;
    status: string;
    status_label: string;
    is_system: boolean;
    is_platform_terms: boolean;
    show_in_footer: boolean;
    version: number | null;
    published_at: string | null;
    href: string;
    can_publish: boolean;
};

export type ContentPageVersionSummary = {
    id: number;
    version: number;
    title: string;
    change_note: string | null;
    author: string | null;
    published_at: string | null;
    is_current: boolean;
};

export type AnnouncementSummary = {
    id: number;
    title: string;
    body: string;
    level: string;
    level_label: string;
    audience: string;
    audience_label: string;
    link_url: string | null;
    link_label: string | null;
    starts_at: string;
    ends_at: string | null;
    is_active: boolean;
    is_showing: boolean;
    has_ended: boolean;
};

/** The banner shape shared with every page in every area. */
export type AnnouncementBanner = {
    id: number;
    title: string;
    body: string;
    level: 'info' | 'warning' | 'critical';
    dismissible: boolean;
    link_url: string | null;
    link_label: string | null;
};

export type StaffMember = {
    id: number;
    name: string;
    email: string;
    status: string;
    status_label: string;
    roles: string[];
    two_factor_ready: boolean;
    is_self: boolean;
    href: string;
};

export type StaffInvitationSummary = {
    id: number;
    email: string;
    name: string;
    role: string;
    role_label: string;
    status: string;
    status_label: string;
    badge: string;
    invited_by: string | null;
    expires_at: string;
    is_open: boolean;
};

export type AuditEntry = {
    id: number;
    action: string;
    actor: string | null;
    actor_id: number | string | null;
    subject_type: string | null;
    subject_label: string | null;
    subject_id: number | string | null;
    reason: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    channel: string | null;
    ip: string | null;
    created_at: string;
};
