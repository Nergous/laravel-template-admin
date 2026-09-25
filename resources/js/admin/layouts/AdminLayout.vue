<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { Head, Link, usePage, router } from "@inertiajs/vue3";
import {
    useTheme,
    NToaster,
    NSidebar,
    NButton,
    NTopbar,
    NSegmented,
    NIcon,
    NAvatar,
    NCommandPalette,
    NDrawer,
    NModal,
    NSpinner,
    NCheckbox,
} from "nergous-ui-vue";
import { useFlashToasts } from "@/admin/composables/useFlashToasts";
import { useHotkeys } from "@/admin/composables/useHotkeys";
import { useServerErrors } from "@/admin/composables/useServerErrors";
import { useSessionTimeout } from "@/admin/composables/useSessionTimeout";
import { can } from "@/lib/can";
import { apiFetch } from "@/lib/api";
import type { Command, Density } from "nergous-ui-vue";
import type { NotificationCategory, SharedProps } from "@/admin/types";

interface NavItem {
    id: string;
    label: string;
    icon: string;
    href: string;
    perm: string | null;
    exact?: boolean;
    badge?: number;
}

defineProps({
    title: { type: String, default: "" },
    subtitle: { type: String, default: "" },
});

const page = usePage<SharedProps>();
const { theme, density, toggle, setDensity } = useTheme();
useFlashToasts();
useServerErrors();

const session = useSessionTimeout(() => page.props.sessionLifetime ?? null);
const sessionWarning = session.warning;
const staying = ref(false);
async function stayInSession() {
    staying.value = true;
    try {
        await session.stayActive();
    } finally {
        staying.value = false;
    }
}
function formatSeconds(total: number): string {
    const m = Math.floor(total / 60);
    const s = total % 60;
    return m > 0 ? `${m}:${String(s).padStart(2, "0")}` : `${s} с`;
}

// Shortcut hint: ⌘ on Apple platforms, Ctrl elsewhere (the hotkey accepts both).
const isMac =
    typeof navigator !== "undefined" &&
    /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
const modKey = isMac ? "⌘" : "Ctrl";

// The collapsed desktop sidebar survives page reloads.
const SIDEBAR_KEY = "admin-sidebar-collapsed";
function readCollapsed(): boolean {
    try {
        return localStorage.getItem(SIDEBAR_KEY) === "1";
    } catch {
        return false;
    }
}
const collapsed = ref(readCollapsed());
watch(collapsed, (value) => {
    try {
        localStorage.setItem(SIDEBAR_KEY, value ? "1" : "0");
    } catch {
        // Storage may be unavailable (private mode); the state stays in memory.
    }
});
const isMobile = ref(false); // < 768px viewport
const drawerOpen = ref(false);
const user = computed(() => page.props.auth.user);
const impersonator = computed(() => page.props.auth.impersonator ?? null);

const MOBILE_BP = 768;
function syncViewport() {
    const mobile = window.innerWidth < MOBILE_BP;
    if (mobile !== isMobile.value) {
        isMobile.value = mobile;
        if (mobile) drawerOpen.value = false;
    }
}
onMounted(() => {
    syncViewport();
    window.addEventListener("resize", syncViewport);
    startNotifRefresh();
});
onBeforeUnmount(() => {
    window.removeEventListener("resize", syncViewport);
    stopNotifRefresh();
});

const sidebarCollapsed = computed(() =>
    isMobile.value ? !drawerOpen.value : collapsed.value,
);

function onTopbarToggle() {
    if (isMobile.value) drawerOpen.value = !drawerOpen.value;
    else collapsed.value = !collapsed.value;
}
function closeDrawer() {
    drawerOpen.value = false;
}

const densityOpts = [
    { value: "compact", label: "S" },
    { value: "comfortable", label: "M" },
    { value: "spacious", label: "L" },
];

// Record counts for the sidebar badges (shared prop from HandleInertiaRequests).
const counts = computed(() => page.props.counts ?? {});

// Unread bell entries. The shared prop arrives with every page; between page
// visits the count is refreshed in the background (see startNotifRefresh).
const notifSeen = ref(false);
const liveNotifCount = ref<number | null>(null);
watch(
    () => counts.value.recentActivity,
    () => {
        notifSeen.value = false;
        liveNotifCount.value = null;
    },
);
const notifCount = computed(() =>
    notifSeen.value
        ? 0
        : (liveNotifCount.value ?? counts.value.recentActivity ?? 0),
);

const NOTIF_REFRESH_MS = 60_000;
let notifTimer: ReturnType<typeof setInterval> | null = null;

async function refreshNotifCount() {
    // An idle tab stops polling, otherwise the poll would keep the session alive forever.
    if (!can("activity-log.view") || document.hidden || !session.isActive())
        return;
    try {
        const res = await apiFetch("/admin/notifications/count");
        if (!res.ok) return;
        const json = (await res.json()) as { count?: number };
        if (typeof json.count !== "number") return;
        if (json.count > 0) notifSeen.value = false;
        liveNotifCount.value = json.count;
    } catch {
        // Offline or signed out: keep the last known value.
    }
}

function onVisibility() {
    if (!document.hidden) refreshNotifCount();
}

function startNotifRefresh() {
    if (!can("activity-log.view")) return;
    notifTimer = setInterval(refreshNotifCount, NOTIF_REFRESH_MS);
    document.addEventListener("visibilitychange", onVisibility);
}

function stopNotifRefresh() {
    if (notifTimer) clearInterval(notifTimer);
    notifTimer = null;
    document.removeEventListener("visibilitychange", onVisibility);
}

function stopImpersonation() {
    router.post("/admin/impersonation/stop");
}

const sections = computed<{ label: string; items: NavItem[] }[]>(() => [
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
        label: "Доступ",
        items: [
            {
                id: "users",
                label: "Пользователи",
                icon: "users",
                href: "/admin/users",
                perm: "users.view",
                badge: counts.value.users ?? undefined,
            },
            {
                id: "roles",
                label: "Роли",
                icon: "shield",
                href: "/admin/roles",
                perm: "roles.view",
                badge: counts.value.roles ?? undefined,
            },
            {
                id: "permissions",
                label: "Разрешения",
                icon: "lock",
                href: "/admin/permissions",
                perm: "permissions.view",
                badge: counts.value.permissions ?? undefined,
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
                badge: counts.value.media ?? undefined,
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
]);

const allItems = computed(() => sections.value.flatMap((s) => s.items));

const groups = computed(() =>
    sections.value
        .map((s) => ({
            label: s.label,
            items: s.items.filter((it) => can(it.perm)),
        }))
        .filter((s) => s.items.length > 0),
);

const activeId = computed(() => {
    const path = page.url.split("?")[0];
    let best: NavItem | null = null;
    for (const it of allItems.value) {
        const match = it.exact ? path === it.href : path.startsWith(it.href);
        if (match && (!best || it.href.length > best.href.length)) best = it;
    }
    return best ? best.id : "";
});

function logout() {
    router.post("/admin/logout");
}

const paletteOpen = ref(false);
const commands = ref<Command[]>([]);

const baseCommands = computed(() => {
    const nav = allItems.value
        .filter((it) => can(it.perm))
        .map((it) => ({
            label: it.label,
            hint: "Переход",
            icon: it.icon,
            action: () => router.visit(it.href),
        }));
    const quick = [
        {
            perm: "users.create",
            label: "Создать пользователя",
            icon: "plus",
            href: "/admin/users/create",
        },
        {
            perm: "roles.create",
            label: "Создать роль",
            icon: "plus",
            href: "/admin/roles/create",
        },
        {
            perm: "media.upload",
            label: "Загрузить файлы",
            icon: "upload",
            href: "/admin/media",
        },
        {
            perm: null,
            label: "Мой профиль",
            icon: "user",
            href: "/admin/profile",
        },
    ]
        .filter((it) => can(it.perm))
        .map((it) => ({
            label: it.label,
            hint: "Действие",
            icon: it.icon,
            action: () => router.visit(it.href),
        }));
    const actions = [
        {
            label: theme.value === "dark" ? "Светлая тема" : "Тёмная тема",
            hint: "Действие",
            icon: theme.value === "dark" ? "sun" : "moon",
            action: toggle,
        },
    ];
    return [...nav, ...quick, ...actions];
});

// Search is debounced: the request fires after a typing pause, not on every keystroke.
const SEARCH_DEBOUNCE = 200; // ms
const SEARCH_MIN_LEN = 2;
let searchTimer: ReturnType<typeof setTimeout> | null = null;
// Only the latest request may update the results: older ones are aborted.
let searchAbort: AbortController | null = null;

function search(q: string) {
    if (searchTimer) clearTimeout(searchTimer);
    searchAbort?.abort();
    const term = q.trim();
    if (term.length < SEARCH_MIN_LEN) {
        const t = term.toLowerCase();
        commands.value = t
            ? baseCommands.value.filter((c) =>
                  c.label.toLowerCase().includes(t),
              )
            : baseCommands.value;
        return;
    }
    searchTimer = setTimeout(() => runSearch(term), SEARCH_DEBOUNCE);
}

async function runSearch(q: string) {
    searchAbort?.abort();
    const controller = new AbortController();
    searchAbort = controller;
    const t = q.toLowerCase();
    const local = baseCommands.value.filter((c) =>
        c.label.toLowerCase().includes(t),
    );
    try {
        const res = await apiFetch(`/admin/search?q=${encodeURIComponent(q)}`, {
            signal: controller.signal,
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (controller.signal.aborted || !paletteOpen.value) return;
        const found = (json.results ?? json).map(
            (r: {
                label: string;
                meta: string;
                icon?: string;
                url: string;
            }) => ({
                label: r.label,
                hint: r.meta,
                icon: r.icon ?? "search",
                action: () => router.visit(r.url),
            }),
        );
        commands.value = [...local, ...found];
    } catch {
        if (!controller.signal.aborted) commands.value = local;
    }
}

// When the palette opens, show the base commands right away.
watch(paletteOpen, (open) => {
    if (open) commands.value = baseCommands.value;
    else {
        if (searchTimer) clearTimeout(searchTimer);
        searchAbort?.abort();
    }
});

useHotkeys({ "mod+k": () => (paletteOpen.value = true) });

const notifOpen = ref(false);
interface NotifItem {
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
const notif = ref<{
    count: number;
    mutes: NotificationCategory[];
    items: NotifItem[];
}>({ count: 0, mutes: [], items: [] });

const NOTIF_CATEGORIES: { value: NotificationCategory; label: string }[] = [
    { value: "auth", label: "Вход и сеансы" },
    { value: "users", label: "Пользователи" },
    { value: "roles", label: "Роли и права" },
    { value: "media", label: "Медиатека" },
    { value: "settings", label: "Настройки" },
    { value: "system", label: "Система" },
];
const notifFilter = ref<NotificationCategory | "">("");
const notifSettingsOpen = ref(false);
const notifSaving = ref(false);
const visibleNotifs = computed(() =>
    notifFilter.value
        ? notif.value.items.filter((it) => it.category === notifFilter.value)
        : notif.value.items,
);
const notifFilterOptions = computed(() =>
    NOTIF_CATEGORIES.filter(
        (c) =>
            !notif.value.mutes.includes(c.value) &&
            notif.value.items.some((it) => it.category === c.value),
    ),
);

async function toggleMute(category: NotificationCategory, shown: boolean) {
    const mutes = shown
        ? notif.value.mutes.filter((c) => c !== category)
        : [...notif.value.mutes, category];
    notifSaving.value = true;
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
        notif.value.mutes = json.mutes;
        if (json.mutes.includes(notifFilter.value as NotificationCategory))
            notifFilter.value = "";
        liveNotifCount.value = json.count;
        await loadNotifications();
    } catch {
        // The checkbox stays as it was; nothing was saved.
    } finally {
        notifSaving.value = false;
    }
}

function changeDensity(value: string | number) {
    setDensity(value as Density);
}
const notifLoading = ref(false);

async function loadNotifications() {
    notifLoading.value = true;
    try {
        const res = await apiFetch("/admin/notifications/recent");
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        notif.value = await res.json();
    } catch {
        notif.value = { count: 0, mutes: notif.value.mutes, items: [] };
    } finally {
        notifLoading.value = false;
    }
}

function openNotifications() {
    notifOpen.value = true;
    loadNotifications().then(markNotificationsSeen);
}

async function markNotificationsSeen() {
    if (!notif.value.count) return;
    try {
        const res = await apiFetch("/admin/notifications/seen", {
            method: "POST",
        });
        if (res.ok) {
            notifSeen.value = true;
            liveNotifCount.value = 0;
        }
    } catch {
        // The badge stays; the next open retries.
    }
}
</script>

<template>
    <div class="admin">
        <Head :title="title" />
        <a href="#admin-main" class="skip-link">Перейти к содержимому</a>
        <NToaster region-label="Уведомления" dismiss-label="Закрыть" />
        <NModal
            :model-value="sessionWarning"
            title="Сессия скоро завершится"
            width="420px"
            close-label="Продолжить работу"
            @update:model-value="(open: boolean) => !open && stayInSession()"
        >
            <p class="session-warn">
                Вы давно не работали в панели. Сессия завершится через
                <b>{{ formatSeconds(session.secondsLeft.value) }}</b
                >, и несохранённые изменения на странице пропадут.
            </p>
            <template #footer>
                <NButton variant="secondary" @click="logout">Выйти</NButton>
                <NButton :loading="staying" @click="stayInSession"
                    >Продолжить работу</NButton
                >
            </template>
        </NModal>
        <NDrawer v-model="notifOpen" title="Уведомления" close-label="Закрыть">
            <div class="notif-tools">
                <div
                    v-if="notifFilterOptions.length > 1"
                    class="notif-chips"
                    role="group"
                    aria-label="Тип событий"
                >
                    <button
                        type="button"
                        class="notif-chip"
                        :class="{ 'notif-chip--on': notifFilter === '' }"
                        :aria-pressed="notifFilter === ''"
                        @click="notifFilter = ''"
                    >
                        Все
                    </button>
                    <button
                        v-for="c in notifFilterOptions"
                        :key="c.value"
                        type="button"
                        class="notif-chip"
                        :class="{ 'notif-chip--on': notifFilter === c.value }"
                        :aria-pressed="notifFilter === c.value"
                        @click="notifFilter = c.value"
                    >
                        {{ c.label }}
                    </button>
                </div>
                <NButton
                    size="sm"
                    variant="ghost"
                    icon="settings"
                    class="notif-tools__gear"
                    :aria-expanded="notifSettingsOpen"
                    @click="notifSettingsOpen = !notifSettingsOpen"
                    >Настроить</NButton
                >
            </div>
            <div v-if="notifSettingsOpen" class="notif-settings">
                <div class="notif-settings__title">Показывать события</div>
                <NCheckbox
                    v-for="c in NOTIF_CATEGORIES"
                    :key="c.value"
                    :model-value="!notif.mutes.includes(c.value)"
                    :disabled="notifSaving"
                    @update:model-value="(v: boolean) => toggleMute(c.value, v)"
                    >{{ c.label }}</NCheckbox
                >
            </div>
            <div v-if="notifLoading" style="text-align: center">
                <NSpinner
                    :size="18"
                    :width="2"
                    class="notif-spinner"
                    label="Загрузка уведомлений..."
                />
            </div>

            <div
                v-if="!visibleNotifs.length && !notifLoading"
                class="notif-empty"
            >
                Свежих событий нет
            </div>
            <Link
                v-for="it in visibleNotifs"
                :key="it.id"
                :href="it.url"
                class="notif-item"
                :class="{ 'notif-item--unread': it.unread }"
                @click="notifOpen = false"
            >
                <div class="notif-item__top">
                    <b>{{ it.user }}</b>
                    <span class="notif-item__time">{{ it.time }}</span>
                </div>
                <div class="notif-item__body">
                    {{ it.action }} · {{ it.subject }}
                    <span v-if="it.repeat > 1" class="notif-item__repeat"
                        >×{{ it.repeat }}</span
                    >
                </div>
            </Link>
        </NDrawer>
        <NCommandPalette
            v-model="paletteOpen"
            :commands="commands"
            :filter="false"
            :shortcut="false"
            placeholder="Поиск, переходы, команды…"
            empty-text="Ничего не найдено"
            nav-hint="навигация"
            select-hint="выбрать"
            @update:query="search"
        />
        <Transition name="admin-backdrop">
            <div
                v-if="isMobile && drawerOpen"
                class="admin__backdrop"
                @click="closeDrawer"
            />
        </Transition>
        <NSidebar
            :model-value="activeId"
            :groups="groups"
            :collapsed="sidebarCollapsed"
            :mobile="isMobile"
            :link-as="Link"
            nav-label="Основная навигация"
            :brand="{
                name: page.props.appName,
                sub: 'Панель администрирования',
                glyph: 'A',
            }"
            @navigate="isMobile && closeDrawer()"
        >
            <template #footer>
                <button
                    type="button"
                    class="sbf__theme"
                    aria-label="Светлая / Тёмная тема"
                    :class="{ 'sbf--collapsed': collapsed }"
                    :title="theme === 'dark' ? 'Светлая тема' : 'Тёмная тема'"
                    @click="toggle"
                >
                    <NIcon
                        :name="theme === 'dark' ? 'sun' : 'moon'"
                        :size="18"
                    />
                    <span v-if="!collapsed">{{
                        theme === "dark" ? "Светлая тема" : "Тёмная тема"
                    }}</span>
                </button>

                <div
                    v-if="user"
                    class="sbf__user"
                    :class="{ 'sbf--collapsed': collapsed }"
                >
                    <Link
                        href="/admin/profile"
                        class="sbf__profile"
                        title="Мой профиль"
                        :aria-label="`Мой профиль: ${user.name}`"
                    >
                        <NAvatar :name="user.name" :size="30" />
                        <div v-if="!collapsed" class="sbf__info">
                            <div class="sbf__name">{{ user.name }}</div>
                            <div class="sbf__role">
                                {{ user.roles?.[0] ?? "" }}
                            </div>
                        </div>
                    </Link>
                    <button
                        type="button"
                        class="sbf__logout"
                        title="Выйти"
                        aria-label="Выйти"
                        @click="logout"
                    >
                        <NIcon name="log-out" :size="16" />
                    </button>
                </div>
            </template>
        </NSidebar>

        <div class="admin__main">
            <div v-if="impersonator && user" class="imp-banner" role="status">
                <NIcon name="user" :size="16" />
                <span class="imp-banner__text">
                    Вы работаете от имени <b>{{ user.name }}</b
                    >. Ваш аккаунт: {{ impersonator.name }}.
                </span>
                <NButton
                    size="sm"
                    variant="secondary"
                    @click="stopImpersonation"
                    >Вернуться к своему аккаунту</NButton
                >
            </div>
            <NTopbar
                :title="title"
                :subtitle="subtitle"
                toggle-label="Свернуть меню"
                @toggle="onTopbarToggle"
            >
                <button
                    type="button"
                    class="admin__cmd"
                    :title="`Поиск и команды (${modKey}+K)`"
                    @click="paletteOpen = true"
                >
                    <NIcon name="search" :size="16" />
                    <span class="admin__cmd__text">Поиск и команды</span>
                    <span class="admin__kbd"
                        ><kbd>{{ modKey }}</kbd
                        ><kbd>K</kbd></span
                    >
                </button>
                <template #right>
                    <NSegmented
                        :model-value="density"
                        :options="densityOpts"
                        aria-label="Плотность интерфейса"
                        @update:model-value="changeDensity"
                    />
                    <NButton
                        variant="secondary"
                        aria-label="Светлая / Тёмная тема"
                        :icon="theme === 'dark' ? 'sun' : 'moon'"
                        @click="toggle"
                    />
                    <span v-if="can('activity-log.view')" class="tb-bell">
                        <NButton
                            variant="secondary"
                            :aria-label="
                                notifCount
                                    ? `Уведомления, новых: ${notifCount}`
                                    : 'Уведомления'
                            "
                            icon="bell"
                            @click="openNotifications"
                        />
                        <span
                            v-if="notifCount"
                            class="tb-bell__badge"
                            aria-hidden="true"
                            >{{ notifCount > 9 ? "9+" : notifCount }}</span
                        >
                    </span>
                </template>
            </NTopbar>

            <main id="admin-main" tabindex="-1" class="admin__body">
                <slot />
            </main>
        </div>
    </div>
</template>

<style scoped>
.admin {
    display: flex;
    height: 100vh;
    width: 100%;
    overflow: hidden;
    background: var(--bg);
}
/* Skip-link: first tab stop, hidden off-screen until focused (WCAG 2.4.1). */
.skip-link {
    position: absolute;
    left: 8px;
    top: -48px;
    z-index: 2000;
    padding: 8px 14px;
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--accent);
    border-radius: var(--radius-md);
    font-weight: 600;
    transition: top 0.15s ease;
}
.skip-link:focus {
    top: 8px;
    outline: 2px solid var(--accent);
}
@media (prefers-reduced-motion: reduce) {
    .skip-link {
        transition: none;
    }
}
.admin__main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}
.imp-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 8px 24px;
    background: var(--warn-bg);
    color: var(--text);
    border-bottom: 1px solid var(--warn);
    font-size: 13.5px;
}
.imp-banner__text {
    flex: 1;
    min-width: 200px;
}
.admin__backdrop {
    position: fixed;
    inset: 0;
    z-index: 1040;
    background: rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(1px);
}
.admin-backdrop-enter-active,
.admin-backdrop-leave-active {
    transition: opacity 0.24s ease;
}
.admin-backdrop-enter-from,
.admin-backdrop-leave-to {
    opacity: 0;
}
.admin__body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
}
.admin__cmd {
    display: flex;
    align-items: center;
    gap: 9px;
    height: 38px;
    padding: 0 12px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text-3);
    font-family: inherit;
    font-weight: 500;
    cursor: pointer;
    width: 280px;
    flex: none;
}
.admin__cmd:not(:disabled):hover {
    background: var(--surface-3);
}
/* only the text label stretches — the ⌘K keys stay on the right */
.admin__cmd__text {
    flex: 1;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.admin__kbd {
    display: flex;
    gap: 3px;
    flex: none;
}
.admin__kbd kbd {
    font-family: inherit;
    font-size: 11px;
    font-weight: 700;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 5px;
    padding: 1px 5px;
    color: var(--text-2);
}

.tb-bell {
    position: relative;
    display: inline-flex;
    flex: none;
}
.tb-bell__badge {
    position: absolute;
    top: -5px;
    right: -5px;
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 4px;
    border-radius: 9px;
    background: var(--danger);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    pointer-events: none;
    border: 2px solid var(--surface);
}

.sbf__theme {
    display: flex;
    align-items: center;
    gap: 11px;
    width: 100%;
    height: 40px;
    padding: 0 11px;
    margin-bottom: 4px;
    border: none;
    border-radius: 9px;
    background: transparent;
    color: var(--text-2);
    font-family: inherit;
    font-weight: 600;
    font-size: 13.5px;
    cursor: pointer;
    transition: 0.14s;
}
.sbf__theme:hover {
    background: var(--surface-3);
    color: var(--text);
}
.sbf__theme.sbf--collapsed {
    justify-content: center;
}
.sbf__user {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 46px;
    padding: 0 8px;
    border-radius: 10px;
    background: var(--surface-3);
}
.sbf__user.sbf--collapsed {
    flex-direction: column;
    justify-content: center;
    height: auto;
    gap: 6px;
    padding: 0;
    background: transparent;
}
.sbf__profile {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
    color: inherit;
    text-decoration: none;
    border-radius: 8px;
}
.sbf__profile:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.sbf--collapsed .sbf__profile {
    flex: none;
}
.sbf__info {
    flex: 1;
    min-width: 0;
    line-height: 1.15;
}
.sbf__name {
    font-weight: 700;
    font-size: 13px;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sbf__role {
    font-size: 11.5px;
    color: var(--text-3);
    white-space: nowrap;
}
.sbf__logout {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    border: none;
    background: transparent;
    color: var(--text-3);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    cursor: pointer;
    transition: 0.14s;
}
.sbf__logout:hover {
    background: var(--danger-bg);
    color: var(--danger);
}

.notif-empty {
    padding: 24px 4px;
    color: var(--text-3);
    text-align: center;
    font-size: 13.5px;
}
.notif-tools {
    display: flex;
    align-items: flex-start;
    gap: var(--sp-2, 8px);
    margin-bottom: 10px;
}
.notif-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    flex: 1;
}
.notif-tools__gear {
    margin-left: auto;
}
.notif-chip {
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-2);
    border-radius: 999px;
    padding: 3px 10px;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
}
.notif-chip--on {
    background: var(--accent-soft);
    border-color: var(--accent);
    color: var(--text);
}
.notif-settings {
    display: grid;
    gap: 8px;
    padding: 12px;
    margin-bottom: 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-2);
}
.notif-settings__title {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.notif-item__repeat {
    margin-left: 6px;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--danger-bg);
    color: var(--danger);
    font-weight: 600;
    font-size: 11.5px;
}
.session-warn {
    margin: 0;
    line-height: 1.5;
    color: var(--text-2);
}
.notif-item {
    display: block;
    padding: 12px;
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--text);
}
.notif-item:hover {
    background: var(--surface-2);
}
.notif-item--unread {
    background: var(--accent-soft);
}
.notif-item__top {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
}
.notif-item__time {
    color: var(--text-3);
}
.notif-item__body {
    font-size: 12.5px;
    color: var(--text-2);
    margin-top: 2px;
}
</style>
