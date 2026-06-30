<script setup>
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
    NSpinner,
} from "@/lib/nergous-cit";
import { useFlashToasts } from "@/admin/composables/useFlashToasts.js";
import { useHotkeys } from "@/admin/composables/useHotkeys.js";
import { can } from "@/lib/can.js";

defineProps({
    title: { type: String, default: "" },
    subtitle: { type: String, default: "" },
});

const page = usePage();
const { theme, density, toggle, setDensity } = useTheme();
useFlashToasts();

const collapsed = ref(false); // desktop: icon-only rail
const isMobile = ref(false); // < 768px viewport
const drawerOpen = ref(false); // mobile: off-canvas drawer visibility
const user = computed(() => page.props.auth.user);

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
});
onBeforeUnmount(() => {
    window.removeEventListener("resize", syncViewport);
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

const notifCount = computed(() => counts.value.recentActivity ?? 0);

const sections = computed(() => [
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
                badge: counts.value.users,
            },
            {
                id: "roles",
                label: "Роли",
                icon: "shield",
                href: "/admin/roles",
                perm: "roles.view",
                badge: counts.value.roles,
            },
            {
                id: "permissions",
                label: "Разрешения",
                icon: "lock",
                href: "/admin/permissions",
                perm: "permissions.view",
                badge: counts.value.permissions,
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
                badge: counts.value.media,
            },
        ],
    },
    ...(page.props.bot?.enabled
        ? [
              {
                  label: "Бот",
                  items: [
                      {
                          id: "botMessages",
                          label: "Сообщения бота",
                          icon: "mail",
                          href: "/admin/bot-messages",
                          perm: "bot-messages.view",
                      },
                  ],
              },
          ]
        : []),
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
    let best = null;
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
const commands = ref([]);

const baseCommands = computed(() => {
    const nav = allItems.value
        .filter((it) => can(it.perm))
        .map((it) => ({
            label: it.label,
            hint: "Переход",
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
    return [...nav, ...actions];
});

// Search is debounced: the request fires after a typing pause, not on every keystroke.
const SEARCH_DEBOUNCE = 200; // ms
const SEARCH_MIN_LEN = 2;
let searchTimer = null;

function search(q) {
    clearTimeout(searchTimer);
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

async function runSearch(q) {
    try {
        const res = await fetch(`/admin/search?q=${encodeURIComponent(q)}`, {
            headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        commands.value = (json.results ?? json).map((r) => ({
            label: r.label,
            hint: r.meta,
            icon: r.icon ?? "search",
            action: () => router.visit(r.url),
        }));
    } catch {
        commands.value = [];
    }
}

// When the palette opens, show the base commands right away.
watch(paletteOpen, (open) => {
    if (open) commands.value = baseCommands.value;
});

useHotkeys({ "mod+k": () => (paletteOpen.value = true) });

const notifOpen = ref(false);
const notif = ref({ count: 0, items: [] });
const notifLoading = ref(false);

async function loadNotifications() {
    notifLoading.value = true;
    try {
        const res = await fetch("/admin/notifications/recent", {
            headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        notif.value = await res.json(); // { count, items: [{ id, user, action, subject, time, url }] }
    } catch {
        notif.value = { count: 0, items: [] };
    } finally {
        notifLoading.value = false;
    }
}

function openNotifications() {
    notifOpen.value = true;
    loadNotifications();
}
</script>

<template>
    <div class="admin">
        <Head :title="title" />
        <a href="#admin-main" class="skip-link">Перейти к содержимому</a>
        <NToaster region-label="Уведомления" dismiss-label="Закрыть" />
        <NDrawer v-model="notifOpen" title="Уведомления" close-label="Закрыть">
            <div v-if="notifLoading" style="text-align: center">
                <NSpinner
                    :size="18"
                    :width="2"
                    class="notif-spinner"
                    label="Загрузка уведомлений..."
                />
            </div>

            <div
                v-if="!notif.items.length && !notifLoading"
                class="notif-empty"
            >
                Свежих событий нет
            </div>
            <Link
                v-for="it in notif.items"
                :key="it.id"
                :href="it.url"
                class="notif-item"
                @click="notifOpen = false"
            >
                <div class="notif-item__top">
                    <b>{{ it.user }}</b>
                    <span class="notif-item__time">{{ it.time }}</span>
                </div>
                <div class="notif-item__body">
                    {{ it.action }} · {{ it.subject }}
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
                    <NAvatar :name="user.name" :size="30" />
                    <div v-if="!collapsed" class="sbf__info">
                        <div class="sbf__name">{{ user.name }}</div>
                        <div class="sbf__role">{{ user.roles?.[0] ?? "" }}</div>
                    </div>
                    <button
                        v-if="!collapsed"
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
            <NTopbar
                :title="title"
                :subtitle="subtitle"
                toggle-label="Свернуть меню"
                @toggle="onTopbarToggle"
            >
                <button
                    type="button"
                    class="admin__cmd"
                    title="Поиск и команды (⌘K)"
                    @click="paletteOpen = true"
                >
                    <NIcon name="search" :size="16" />
                    <span class="admin__cmd__text">Поиск и команды</span>
                    <span class="admin__kbd"><kbd>⌘</kbd><kbd>K</kbd></span>
                </button>
                <template #right>
                    <NSegmented
                        :model-value="density"
                        :options="densityOpts"
                        aria-label="Плотность интерфейса"
                        @update:model-value="setDensity"
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

/* --- Notification bell in the topbar: badge with the count of unseen events --- */
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

/* --- Sidebar footer: theme toggle + user card --- */
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
    justify-content: center;
    padding: 0;
    background: transparent;
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
