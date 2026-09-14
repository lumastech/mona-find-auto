/**
 * Shapes owned by the Mechanics module: profiles, specialities, endorsements
 * and the public directory.
 */

import type { SellerContact } from './sellers';

export type MechanicStatusValue =
    | 'draft'
    | 'submitted'
    | 'under_review'
    | 'approved'
    | 'rejected'
    | 'suspended';

export type EndorsementStatusValue =
    | 'requested'
    | 'endorsed'
    | 'declined'
    | 'revoked';

export type MechanicSpeciality = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
};

/**
 * One shop's badge on a mechanic's profile.
 *
 * The public half of an endorsement: a name and a date. The message the
 * mechanic wrote when asking is not here, because it is between the two of
 * them — see MechanicEndorsement for the private view.
 */
export type MechanicEndorsementBadge = {
    id: number;
    /** "Endorsed by Kabwata Motors". */
    label: string;
    seller: { slug: string; business_name: string; verified: boolean };
    endorsed_at: string | null;
};

export type MechanicWorkHistoryEntry = {
    employer: string;
    role: string;
    description: string | null;
    /** "Mar 2014 — present". */
    period: string;
    is_current: boolean;
};

/**
 * A mechanic as a public surface shows them.
 *
 * Two independent badges: `approved` is MonaFind's, `endorsements` are the
 * shops'. Neither implies the other.
 *
 * `rating.average` is null when nobody has reviewed them yet, which is not
 * the same as zero and must not be rendered as one.
 */
export type MechanicProfile = {
    id: number;
    slug: string;
    display_name: string;
    headline: string | null;
    bio: string | null;

    qualification: string;
    qualification_institution: string | null;
    qualification_year: number | null;
    years_experience: number;

    approved: boolean;
    approved_at: string | null;

    is_mobile: boolean;
    accepting_work: boolean;

    location: {
        province: string;
        city: string;
        locality: string;
        latitude: number | null;
        longitude: number | null;
    };

    /** Masked by the server for guests — the same shape a seller's block has. */
    contact: SellerContact;

    specialities: MechanicSpeciality[];
    endorsements: MechanicEndorsementBadge[];
    work_history: MechanicWorkHistoryEntry[];

    avatar_url: string | null;
    rating: { average: number | null; count: number };
    can: { message: boolean };
    member_since: string | null;
};

/**
 * The private view of an endorsement: the seller portal's queue, and the
 * mechanic's own list of who they have asked.
 */
export type MechanicEndorsement = {
    id: number;
    status: EndorsementStatusValue;
    status_label: string;
    status_variant: string;

    mechanic: {
        id: number;
        slug: string;
        display_name: string;
        headline: string | null;
        qualification: string;
        years_experience: number;
        locality: string;
        approved: boolean;
        avatar_url: string | null;
    };

    seller: {
        id: number;
        slug: string;
        business_name: string;
        verified: boolean;
    };

    message: string | null;
    response_note: string | null;
    revocation_reason: string | null;

    requested_at: string | null;
    decided_at: string | null;
    endorsed_at: string | null;
    revoked_at: string | null;

    can: { decide: boolean; revoke: boolean };
};

export type MechanicDirectoryFilters = {
    search: string | null;
    speciality_id: number | null;
    province_id: number | null;
    city_id: number | null;
    min_rating: number | null;
    accepting_work: boolean;
    endorsed_only: boolean;
};

/** Where the applicant's own profile stands, on the application page. */
export type MechanicApplicationStatus = {
    value: MechanicStatusValue;
    label: string;
    variant: string;
    guidance: string;
    editable: boolean;
    submitted_at: string | null;
    rejection_reason: string | null;
    /** Set only once the profile is live. */
    public_url: string | null;
};

/**
 * The application form's saved answers.
 *
 * `references` appear here and on no other surface — they are a third party's
 * phone number given to MonaFind for one purpose.
 */
export type MechanicApplicationForm = {
    exists: boolean;
    display_name: string;
    headline: string | null;
    bio: string | null;
    qualification: string;
    qualification_institution: string | null;
    qualification_year: number | null;
    years_experience: number;
    province_id: number | null;
    city_id: number | null;
    street: string | null;
    plot_number: string | null;
    phone: string;
    email: string | null;
    is_mobile: boolean;
    accepting_work: boolean;
    speciality_ids: number[];
    work_history: {
        employer: string;
        role: string;
        description: string | null;
        started_on: string | null;
        ended_on: string | null;
        is_current: boolean;
    }[];
    references: {
        name: string;
        relationship: string | null;
        phone: string;
        email: string | null;
        note: string | null;
    }[];
};

/** A page of the directory, as Laravel's paginator hands it over. */
export type MechanicDirectoryPage = {
    data: MechanicProfile[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

/** A page of endorsements in the seller portal. */
export type MechanicEndorsementPage = {
    data: MechanicEndorsement[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

/** A province with its towns, as the location directory supplies them. */
export type ProvinceWithCities = {
    id: number;
    name: string;
    cities: { id: number; name: string; is_major: boolean }[];
};
