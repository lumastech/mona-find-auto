/**
 * Shapes owned by the Messaging module: notifications and conversations.
 *
 * Two things are worth pointing out.
 *
 * A notification is rendered without the page knowing what kind it is. Every
 * notification on the platform writes a `title`, a `body` and an optional
 * action, so the bell draws one added after the app shipped — which is why
 * `data` is an open record rather than a union: reading into it is the
 * exception, not the way the list works.
 *
 * `allows_contact_details` on a thread is not decoration. It is false until
 * the thread's order is paid, and the composer uses it to warn BEFORE
 * somebody types a phone number — telling them afterwards, with the number
 * already gone, reads as the platform having lost their message.
 */

export type NotificationChannelValue = 'database' | 'mail' | 'sms';

export type ThreadRoleValue = 'buyer' | 'seller' | 'mechanic';

/** The two unread numbers behind the bell. Null for a guest. */
export type MessagingCounts = {
    unread_notifications: number;
    unread_threads: number;
};

export type AppNotification = {
    id: string;
    /** The catalogue key, e.g. "orders.placed". */
    event: string | null;
    title: string;
    body: string | null;
    action_url: string | null;
    action_label: string | null;
    read_at: string | null;
    created_at: string | null;
    data: Record<string, unknown>;
};

export type ThreadParty = {
    id: number;
    name: string;
    role: ThreadRoleValue;
    role_label: string;
};

export type MessageAttachment = {
    id: number;
    name: string;
    mime_type: string;
    size: number;
    url: string;
    thumb_url: string | null;
};

export type ThreadMessage = {
    id: number;
    body: string;
    is_mine: boolean;
    is_from_platform: boolean;
    author: { id: number | null; name: string | null } | null;
    /** Whether the screen took something out of this message. */
    was_redacted: boolean;
    /** Labels of what was removed, e.g. "Phone number redacted". */
    redactions: string[];
    attachments: MessageAttachment[];
    created_at: string | null;
};

export type MessageThread = {
    id: number;
    subject_label: string;
    /** "Product", "Order", "Quotation", "Seller" — for the icon. */
    subject_type: string;
    subject_url: string | null;
    my_role: ThreadRoleValue | null;
    counterpart: ThreadParty | null;
    messages_count: number;
    last_message_at: string | null;
    is_unread: boolean;
    is_closed: boolean;
    /** False until the thread's order is paid; the composer warns on it. */
    allows_contact_details: boolean;
    messages?: ThreadMessage[];
    created_at: string | null;
};

/*
 * The preference and matrix screens.
 */

export type PreferenceChannel = {
    channel: NotificationChannelValue;
    label: string;
    /** Whether the platform matrix allows this channel for this event. */
    available: boolean;
    enabled: boolean;
    /** Mandatory event, or a channel the matrix has switched off. */
    locked: boolean;
};

export type PreferenceEvent = {
    event: string;
    label: string;
    description: string;
    /** Verification codes and suspensions: sent whatever the person asks. */
    mandatory: boolean;
    channels: PreferenceChannel[];
};

export type PreferenceGroup = {
    group: string;
    events: PreferenceEvent[];
};

export type MatrixChannel = {
    value: NotificationChannelValue;
    label: string;
    description: string;
};

export type MatrixEvent = {
    event: string;
    label: string;
    description: string;
    mandatory: boolean;
};

export type MatrixGroup = {
    group: string;
    events: MatrixEvent[];
};
