import type { PageProps } from "@inertiajs/core";

export interface AdminUser {
    [key: string]: unknown;
    id: number;
    name: string;
    email: string;
    roles: { id?: number; name: string; description?: string | null }[];
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
    created_at?: string;
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
    changesCount: number;
    changes: Record<string, unknown> | null;
    createdAt: string | null;
}

export interface SharedProps extends PageProps {
    appName: string;
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
        recentActivity?: number | null;
    } | null;
    flash: Partial<
        Record<"success" | "error" | "warning" | "info", string | null>
    >;
}
