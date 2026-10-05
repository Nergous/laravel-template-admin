import type { SharedProps } from "@/admin/types";

export interface NavItem {
    id: string;
    label: string;
    icon: string;
    href: string;
    perm: string | null;
    exact?: boolean;
    badge?: number;
}

export interface NavSection {
    label: string;
    items: NavItem[];
}

type Counts = NonNullable<SharedProps["counts"]>;

/**
 * Sidebar sections; badges come from the shared record counts. Content goes
 * first (add project sections there): it is the daily work of most users,
 * access and system pages are rare.
 * Every item has its own icon, so a collapsed sidebar stays readable.
 */
export function buildNavSections(counts: Counts): NavSection[] {
    return [
        {
            label: "Обзор",
            items: [
                {
                    id: "dashboard",
                    label: "Главная",
                    icon: "home",
                    href: "/admin",
                    perm: null,
                    exact: true,
                },
            ],
        },
        {
            label: "Контент",
            items: [
                {
                    id: "media",
                    label: "Медиатека",
                    icon: "asset",
                    href: "/admin/media",
                    perm: "media.view",
                    badge: counts.media ?? undefined,
                },
            ],
        },
        {
            label: "Доступ",
            items: [
                {
                    id: "users",
                    label: "Пользователи",
                    icon: "users",
                    href: "/admin/users",
                    perm: "users.view",
                    badge: counts.users ?? undefined,
                },
                {
                    id: "roles",
                    label: "Роли",
                    icon: "shield",
                    href: "/admin/roles",
                    perm: "roles.view",
                    badge: counts.roles ?? undefined,
                },
                {
                    id: "permissions",
                    label: "Разрешения",
                    icon: "lock",
                    href: "/admin/permissions",
                    perm: "permissions.view",
                    badge: counts.permissions ?? undefined,
                },
            ],
        },
        {
            label: "Система",
            items: [
                {
                    id: "activityLog",
                    label: "Журнал действий",
                    icon: "activity",
                    href: "/admin/activity-log",
                    perm: "activity-log.view",
                },
                {
                    id: "settings",
                    label: "Настройки",
                    icon: "settings",
                    href: "/admin/settings",
                    perm: "settings.view",
                },
                {
                    id: "backups",
                    label: "Резервные копии",
                    icon: "download",
                    href: "/admin/backups",
                    perm: "backups.view",
                },
                {
                    id: "queue",
                    label: "Очередь задач",
                    icon: "layers",
                    href: "/admin/queue",
                    perm: "queue.view",
                },
            ],
        },
    ];
}
