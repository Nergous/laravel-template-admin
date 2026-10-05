<script setup lang="ts">
import type { PropType } from "vue";
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { Head, Link, usePage, router } from "@inertiajs/vue3";
import {
    useTheme,
    NToaster,
    NSidebar,
    NButton,
    NTopbar,
    NIcon,
    NAvatar,
    NModal,
    NSkeleton,
    NBreadcrumbs,
    NIconTooltip,
} from "nergous-ui-vue";
import { useFlashToasts } from "@/admin/composables/useFlashToasts";
import type { Crumb } from "@/admin/composables/usePageHeader";
import { useServerErrors } from "@/admin/composables/useServerErrors";
import { useSessionTimeout } from "@/admin/composables/useSessionTimeout";
import { useVisitState } from "@/admin/composables/useVisitState";
import { can } from "@/lib/can";
import type { SharedProps } from "@/admin/types";
import AdminCommandPalette from "./partials/AdminCommandPalette.vue";
import NotificationBell from "./partials/NotificationBell.vue";
import { buildNavSections, type NavItem } from "./partials/nav";

// Persistent layout (see app.ts): mounted once for all admin pages. The page
// header arrives as Inertia layout props set by usePageHeader().
defineProps({
    title: { type: String, default: "" },
    subtitle: { type: String, default: "" },
    /** Parent pages of the breadcrumb trail; the title closes it. */
    crumbs: { type: Array as PropType<Crumb[]>, default: () => [] },
});

const page = usePage<SharedProps>();
const { theme, toggle } = useTheme();
useFlashToasts();
useServerErrors();

const { navigating, refreshing } = useVisitState();

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

function stopImpersonation() {
    router.post("/admin/impersonation/stop");
}

// Record counts for the sidebar badges (shared prop from HandleInertiaRequests).
const sections = computed(() => buildNavSections(page.props.counts ?? {}));
const allItems = computed(() => sections.value.flatMap((s) => s.items));
const allowedItems = computed(() =>
    allItems.value.filter((it) => can(it.perm)),
);

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
</script>

<template>
    <div class="admin">
        <Head :title="title" />
        <a href="#admin-main" class="skip-link">Перейти к содержимому</a>
        <NToaster />
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
                <NButton
                    :loading="staying"
                    data-enter-submit
                    @click="stayInSession"
                    >Продолжить работу</NButton
                >
            </template>
        </NModal>
        <AdminCommandPalette v-model="paletteOpen" :items="allowedItems" />
        <NIconTooltip />
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
                    <NotificationBell v-if="can('activity-log.view')" />
                </template>
            </NTopbar>

            <!-- scroll-region: Inertia resets (or preserves) this scroll on visits. -->
            <main
                id="admin-main"
                tabindex="-1"
                class="admin__body"
                :class="{
                    'is-navigating': navigating,
                    'is-refreshing': refreshing,
                }"
                :aria-busy="navigating || refreshing || undefined"
                scroll-region
            >
                <div
                    v-if="navigating"
                    class="admin__skeleton"
                    aria-hidden="true"
                >
                    <NSkeleton width="38%" height="22px" />
                    <div class="admin__skeleton-bar">
                        <NSkeleton width="60%" height="38px" radius="10px" />
                        <NSkeleton width="20%" height="38px" radius="10px" />
                    </div>
                    <div class="admin__skeleton-card">
                        <NSkeleton
                            v-for="n in 6"
                            :key="n"
                            :width="n % 2 ? '92%' : '78%'"
                            height="16px"
                        />
                    </div>
                </div>
                <NBreadcrumbs
                    v-if="crumbs.length"
                    class="crumbs"
                    :items="[...crumbs, { label: title }]"
                    :link-as="Link"
                />
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
/* A slow visit to another page: the skeleton stands in for the old page. */
.admin__body.is-navigating > :deep(:not(.admin__skeleton)) {
    display: none;
}
.admin__skeleton {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.admin__skeleton-bar {
    display: flex;
    gap: 10px;
}
.admin__skeleton-card {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 22px 20px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}
/* The same list reloads with new filters: dim what is about to change. */
.admin__body.is-refreshing :deep(.n-table-wrap),
.admin__body.is-refreshing :deep(.n-card) {
    opacity: 0.55;
    transition: opacity 0.15s ease;
}
.crumbs {
    margin: -6px 0 14px;
}
@media (max-width: 640px) {
    .admin__body {
        padding: 16px 12px;
    }
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
/* On a phone the search button shrinks to its icon so the topbar fits; the
   label stays for screen readers. After the base rules: same specificity. */
@media (max-width: 640px) {
    .n-tb {
        padding: 0 12px;
        gap: 10px;
    }
    .admin__cmd {
        width: 38px;
        padding: 0;
        justify-content: center;
    }
    .admin__cmd__text {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }
    .admin__kbd {
        display: none;
    }
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

.session-warn {
    margin: 0;
    line-height: 1.5;
    color: var(--text-2);
}
</style>
