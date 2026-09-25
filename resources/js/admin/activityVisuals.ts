/** Tone of an activity entry's icon chip. */
export type ActivityTone = "ok" | "info" | "danger" | "accent";

const VISUAL: Record<string, { tone: ActivityTone; icon: string }> = {
    created: { tone: "ok", icon: "plus" },
    updated: { tone: "info", icon: "edit" },
    deleted: { tone: "danger", icon: "trash" },
    force_deleted: { tone: "danger", icon: "trash" },
    restored: { tone: "ok", icon: "check" },
    duplicated: { tone: "accent", icon: "copy" },
    login: { tone: "info", icon: "user" },
    login_failed: { tone: "danger", icon: "alert-triangle" },
    cleared: { tone: "danger", icon: "eraser" },
    backup_created: { tone: "accent", icon: "shield" },
    backup_downloaded: { tone: "info", icon: "download" },
    backup_deleted: { tone: "danger", icon: "trash" },
    backup_restored: { tone: "accent", icon: "shield" },
    logout: { tone: "info", icon: "log-out" },
    session_ended: { tone: "info", icon: "lock" },
    sessions_ended: { tone: "info", icon: "lock" },
    impersonation_started: { tone: "accent", icon: "eye" },
    impersonation_stopped: { tone: "accent", icon: "eye-off" },
    settings_updated: { tone: "info", icon: "settings" },
    users_exported: { tone: "info", icon: "download" },
    upload_failed: { tone: "danger", icon: "alert-triangle" },
    upload_duplicate: { tone: "info", icon: "copy" },
    media_cropped: { tone: "info", icon: "edit" },
    folder_renamed: { tone: "info", icon: "edit" },
    folder_cleared: { tone: "danger", icon: "trash" },
    backup_failed: { tone: "danger", icon: "alert-triangle" },
    job_retried: { tone: "accent", icon: "bolt" },
    job_deleted: { tone: "danger", icon: "trash" },
};

const FALLBACK: { tone: ActivityTone; icon: string } = {
    tone: "info",
    icon: "edit",
};

/** Icon and tone for an activity-log action; the verb itself comes from the server. */
export function activityVisual(action: string): {
    tone: ActivityTone;
    icon: string;
} {
    return VISUAL[action] ?? FALLBACK;
}
