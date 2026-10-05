import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { apiFetch } from "@/lib/api";
import { isUserActive } from "@/admin/composables/useSessionTimeout";
import type { NotificationCategory, SharedProps } from "@/admin/types";

export interface NotificationItem {
    id: number;
    url: string;
    user: string;
    time: string;
    action: string;
    subject: string;
    category: NotificationCategory;
    repeat: number;
    unread?: boolean;
}

export const NOTIFICATION_CATEGORIES: {
    value: NotificationCategory;
    label: string;
}[] = [
    { value: "auth", label: "Вход и сеансы" },
    { value: "users", label: "Пользователи" },
    { value: "roles", label: "Роли и права" },
    { value: "media", label: "Медиатека" },
    { value: "settings", label: "Настройки" },
    { value: "system", label: "Система" },
];

const REFRESH_MS = 60_000;

/**
 * The notification bell: unread count, feed, category filter and mutes.
 *
 * The count arrives with every page (shared prop counts.recentActivity) and is
 * refreshed in the background between visits while the user works in a
 * visible tab; an idle tab stops polling, otherwise the poll would keep the
 * session alive forever.
 */
export function useNotifications() {
    const page = usePage<SharedProps>();

    const seen = ref(false);
    const liveCount = ref<number | null>(null);
    const sharedCount = computed(() => page.props.counts?.recentActivity);
    watch(sharedCount, () => {
        seen.value = false;
        liveCount.value = null;
    });
    const count = computed(() =>
        seen.value ? 0 : (liveCount.value ?? sharedCount.value ?? 0),
    );

    const open = ref(false);
    const loading = ref(false);
    const saving = ref(false);
    const settingsOpen = ref(false);
    const filter = ref<NotificationCategory | "">("");
    const feed = ref<{
        count: number;
        mutes: NotificationCategory[];
        items: NotificationItem[];
    }>({ count: 0, mutes: [], items: [] });

    const visibleItems = computed(() =>
        filter.value
            ? feed.value.items.filter((it) => it.category === filter.value)
            : feed.value.items,
    );
    const filterOptions = computed(() =>
        NOTIFICATION_CATEGORIES.filter(
            (c) =>
                !feed.value.mutes.includes(c.value) &&
                feed.value.items.some((it) => it.category === c.value),
        ),
    );

    async function refreshCount() {
        if (document.hidden || !isUserActive()) return;
        try {
            const res = await apiFetch("/admin/notifications/count");
            if (!res.ok) return;
            const json = (await res.json()) as { count?: number };
            if (typeof json.count !== "number") return;
            if (json.count > 0) seen.value = false;
            liveCount.value = json.count;
        } catch {
            // Offline or signed out: keep the last known value.
        }
    }

    async function load() {
        loading.value = true;
        try {
            const res = await apiFetch("/admin/notifications/recent");
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            feed.value = await res.json();
        } catch {
            feed.value = { count: 0, mutes: feed.value.mutes, items: [] };
        } finally {
            loading.value = false;
        }
    }

    async function markSeen() {
        if (!feed.value.count) return;
        try {
            const res = await apiFetch("/admin/notifications/seen", {
                method: "POST",
            });
            if (res.ok) {
                seen.value = true;
                liveCount.value = 0;
            }
        } catch {
            // The badge stays; the next open retries.
        }
    }

    function show() {
        open.value = true;
        load().then(markSeen);
    }

    async function toggleMute(category: NotificationCategory, shown: boolean) {
        const mutes = shown
            ? feed.value.mutes.filter((c) => c !== category)
            : [...feed.value.mutes, category];
        saving.value = true;
        try {
            const res = await apiFetch("/admin/notifications/preferences", {
                method: "PUT",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ mutes }),
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = (await res.json()) as {
                mutes: NotificationCategory[];
                count: number;
            };
            feed.value.mutes = json.mutes;
            if (json.mutes.includes(filter.value as NotificationCategory))
                filter.value = "";
            liveCount.value = json.count;
            await load();
        } catch {
            // The checkbox stays as it was; nothing was saved.
        } finally {
            saving.value = false;
        }
    }

    let timer: ReturnType<typeof setInterval> | null = null;
    function onVisibility() {
        if (!document.hidden) refreshCount();
    }
    onMounted(() => {
        timer = setInterval(refreshCount, REFRESH_MS);
        document.addEventListener("visibilitychange", onVisibility);
    });
    onBeforeUnmount(() => {
        if (timer) clearInterval(timer);
        timer = null;
        document.removeEventListener("visibilitychange", onVisibility);
    });

    return {
        count,
        open,
        loading,
        saving,
        settingsOpen,
        filter,
        feed,
        visibleItems,
        filterOptions,
        show,
        toggleMute,
    };
}
