import type { PageProps } from "@inertiajs/core";

// Row shapes of list pages are type aliases (not interfaces): an alias is
// implicitly assignable to the table's Record<string, unknown> row, so no
// index signature that would also hide misspelled field names is needed.

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    roles: { id?: number; name: string; description?: string | null }[];
    is_active?: boolean;
    /** Why the account is blocked (only while is_active is false). */
    blocked_reason?: string | null;
    last_login_at?: string | null;
    /** Whether the current user may edit/delete this account (server rule). */
    can_manage?: boolean;
    created_at?: string | null;
    updated_at?: string | null;
    deleted_at?: string | null;
    creator_name?: string | null;
    editor_name?: string | null;
};

export type AdminRole = {
    id: number;
    name: string;
    description?: string | null;
    is_system?: boolean;
    can_edit?: boolean;
    can_delete?: boolean;
    permissions_count?: number;
    users_count?: number;
    permission_names?: string[];
    creator_name?: string | null;
    editor_name?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
};

/** Permission groups of the role form: { users: [{ id, name }], media: [...], … }. */
export type PermissionGroups = Record<string, { id?: number; name: string }[]>;

export interface MediaItem {
    id: number;
    filename: string;
    original_name: string;
    mime_type?: string;
    type: string;
    size: number;
    url: string;
    thumb_url?: string | null;
    /** Responsive candidates ("url 600w, url 960w, …"); empty for non-images. */
    srcset?: string;
    width?: number | null;
    height?: number | null;
    /** Focal point as fractions of the width/height; null — the center. */
    focal_x?: number | null;
    focal_y?: number | null;
    created_at?: string;
    alt?: string | null;
    folder?: string | null;
    /** Places that reference the file (settings favicon, OG image…). */
    usages?: string[];
}

/** File details from GET /admin/media/{id}. */
export interface MediaDetails extends MediaItem {
    dimensions: { width: number; height: number } | null;
    creator_name: string | null;
    editor_name: string | null;
    updated_at: string | null;
    /** Where the file is referenced, with a link to edit each place. */
    places: MediaPlace[];
}

export interface MediaPlace {
    label: string;
    title: string | null;
    url: string | null;
}

export interface SelectOption {
    value: string;
    label: string;
}

/** Picked library file as the admin forms keep it (see MediaField). */
export interface MediaRef {
    id: number;
    type?: string;
    url: string;
    thumb_url?: string | null;
    original_name?: string | null;
    size?: number | null;
    alt?: string | null;
}

/** Settings of the /admin/settings page by group (SettingsSchema::SETTINGS_PAGE). */
export type AdminSettings = {
    general: { app_name: string; timezone: string; favicon: string };
    seo: {
        meta_title_template: string;
        meta_description: string;
        canonical_domain: string;
        og_image: string;
        indexable: boolean;
        sitemap: boolean;
    };
    security: { session_lifetime: number; login_throttle: number };
    appearance: { accent: string };
};

export interface Pagination<T> {
    data: T[];
    total: number;
    current_page: number;
    last_page: number;
    from?: number | null;
    to?: number | null;
    per_page?: number;
    links?: unknown[];
}

export interface AuditLog {
    id: number;
    action: string;
    action_label: string;
    actor: string;
    subject: string;
    subject_type: string;
    subject_url: string | null;
    changes_count: number;
    changes: Record<string, unknown> | null;
    created_at: string | null;
}

export interface SharedProps extends PageProps {
    appName: string;
    /** Sidebar brand icon URL: the favicon setting or the default favicon. */
    brandIcon?: string;
    /** Display time zone (IANA); dates arrive as UTC ISO strings. */
    timezone: string;
    /** Idle session lifetime in minutes; null with a "remember me" cookie. */
    sessionLifetime?: number | null;
    auth: {
        user: {
            id: number;
            name: string;
            email: string;
            roles: string[];
        } | null;
        can: string[];
    };
    counts: {
        users?: number | null;
        roles?: number | null;
        permissions?: number | null;
        media?: number | null;
    } | null;
    flash: Partial<
        Record<"success" | "error" | "warning" | "info", string | null>
    >;
}
