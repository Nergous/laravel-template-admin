import type { PageProps } from "@inertiajs/core";

export interface AdminUser {
    [key: string]: unknown;
    id: number;
    name: string;
    email: string;
    roles: { id?: number; name: string; description?: string | null }[];
    is_active?: boolean;
    /** Why the account is blocked (only while is_active is false). */
    blocked_reason?: string | null;
    must_change_password?: boolean;
    last_login_at?: string | null;
    /** Whether the current user may edit/delete this account (server rule). */
    can_manage?: boolean;
    created_at?: string | null;
    updated_at?: string | null;
    deleted_at?: string | null;
    creator?: { name: string } | null;
    editor?: { name: string } | null;
}

export interface AdminRole {
    id: number;
    name: string;
    description?: string | null;
    is_system?: boolean;
    can_edit?: boolean;
    permissions_count?: number;
    users_count?: number;
    permission_names?: string[];
    creator_name?: string | null;
    editor_name?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
}

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
    created_local?: string;
    alt?: string | null;
    folder?: string | null;
    /** Places that reference the file (settings favicon, OG image…). */
    usages?: string[];
}

/** File details from GET /admin/media/{id}. */
export interface MediaDetails extends MediaItem {
    dimensions: { width: number; height: number } | null;
    uploaded_by: string | null;
    updated_by: string | null;
    updated_at: string | null;
}

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
    actionLabel: string;
    actor: string;
    subject: string;
    subjectType: string;
    subjectUrl: string | null;
    changesCount: number;
    changes: Record<string, unknown> | null;
    createdAt: string | null;
}

export interface SharedProps extends PageProps {
    appName: string;
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
            must_change_password: boolean;
        } | null;
        /** The administrator behind a "sign in as" session, null otherwise. */
        impersonator?: { id: number; name: string } | null;
        can: string[];
    };
    counts: {
        users?: number | null;
        roles?: number | null;
        permissions?: number | null;
        media?: number | null;
        recentActivity?: number | null;
    } | null;
    flash: Partial<
        Record<"success" | "error" | "warning" | "info", string | null>
    >;
}

/** Bell notification categories (ActivityLog::NOTIFICATION_CATEGORIES). */
export type NotificationCategory =
    | "auth"
    | "users"
    | "roles"
    | "media"
    | "settings"
    | "system";
