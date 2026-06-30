<script setup>
// Full admin demo — drop into a fresh Vite Vue project as App.vue.
import { ref, computed } from "vue";
import {
    NSidebar,
    NTopbar,
    NStatCard,
    NDataTable,
    NDropzone,
    NEmptyState,
    NBadge,
    NAvatar,
    NButton,
    NInput,
    NSelect,
    NModal,
    NDrawer,
    NToaster,
    NCommandPalette,
    NIcon,
    NSegmented,
    useTheme,
    useToast,
} from "../index.js";

const { theme, toggle, density, setDensity } = useTheme();
const toast = useToast();

const view = ref("overview");
const collapsed = ref(false);
const cmdOpen = ref(false);

const groups = [
    {
        label: "Рабочее пространство",
        items: [
            { id: "overview", label: "Обзор", icon: "home" },
            { id: "members", label: "Участники", icon: "users", badge: 8 },
            { id: "assets", label: "Ассеты", icon: "asset" },
        ],
    },
    {
        label: "Система",
        items: [{ id: "settings", label: "Настройки", icon: "settings" }],
    },
];
const titles = {
    overview: ["Обзор", "Добро пожаловать, Анна"],
    members: ["Участники", "Управление доступом команды"],
    assets: ["Медиатека", "Файлы рабочего пространства"],
    settings: ["Настройки", "Конфигурация пространства"],
};
const pageTitle = computed(() => titles[view.value]?.[0] || "");
const pageSub = computed(() => titles[view.value]?.[1] || "");

// --- stat cards ---
const kpis = [
    {
        label: "Участников",
        value: "1 248",
        delta: "+12%",
        trend: "up",
        spark: [8, 11, 9, 14, 12, 18, 16, 22, 19, 26, 24, 31],
    },
    {
        label: "Активны сейчас",
        value: "64",
        delta: "+4",
        trend: "up",
        spark: [3, 5, 4, 6, 8, 7, 9, 11, 10, 13, 12, 15],
    },
    {
        label: "Хранилище",
        value: "3.2 ГБ",
        delta: "+0.4",
        trend: "up",
        spark: [2, 3, 3, 4, 5, 5, 6, 6, 7, 8, 8, 9],
    },
    {
        label: "Приглашений",
        value: "4",
        delta: "−2",
        trend: "down",
        spark: [5, 6, 8, 7, 10, 12, 11, 9, 8, 6, 5, 4],
    },
];

// --- members table ---
const columns = [
    { key: "name", label: "Участник", sortable: true, width: "34%" },
    { key: "role", label: "Роль", sortable: true, width: "14%" },
    { key: "status", label: "Статус", sortable: true, width: "16%" },
    { key: "plan", label: "Тариф", sortable: true, width: "14%" },
    { key: "lastActive", label: "Активность", sortable: true, width: "16%" },
];
const members = ref([
    {
        id: 1,
        name: "Анна Кириллова",
        email: "anna.k@nergous-cit.app",
        role: "Owner",
        status: "active",
        plan: "Enterprise",
        lastActive: "2 мин назад",
    },
    {
        id: 2,
        name: "Дмитрий Соколов",
        email: "d.sokolov@nergous-cit.app",
        role: "Admin",
        status: "active",
        plan: "Pro",
        lastActive: "1 ч назад",
    },
    {
        id: 3,
        name: "Мария Орлова",
        email: "m.orlova@nergous-cit.app",
        role: "Editor",
        status: "active",
        plan: "Pro",
        lastActive: "3 ч назад",
    },
    {
        id: 4,
        name: "Игорь Лебедев",
        email: "i.lebedev@nergous-cit.app",
        role: "Editor",
        status: "invited",
        plan: "Free",
        lastActive: "—",
    },
    {
        id: 5,
        name: "Светлана Зайцева",
        email: "s.zaytseva@nergous-cit.app",
        role: "Viewer",
        status: "active",
        plan: "Free",
        lastActive: "вчера",
    },
    {
        id: 6,
        name: "Павел Морозов",
        email: "p.morozov@nergous-cit.app",
        role: "Admin",
        status: "suspended",
        plan: "Pro",
        lastActive: "5 дн назад",
    },
    {
        id: 7,
        name: "Ольга Новикова",
        email: "o.novikova@nergous-cit.app",
        role: "Editor",
        status: "active",
        plan: "Enterprise",
        lastActive: "12 мин назад",
    },
    {
        id: 8,
        name: "Артём Волков",
        email: "a.volkov@nergous-cit.app",
        role: "Viewer",
        status: "invited",
        plan: "Free",
        lastActive: "—",
    },
]);
const search = ref("");
const selected = ref([]);
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return q
        ? members.value.filter((m) =>
              (m.name + " " + m.email + " " + m.role).toLowerCase().includes(q),
          )
        : members.value;
});
const roleTone = {
    Owner: "accent",
    Admin: "info",
    Editor: "neutral",
    Viewer: "neutral",
};
const statusTone = { active: "ok", invited: "warn", suspended: "danger" };
const statusLabel = {
    active: "Активен",
    invited: "Приглашён",
    suspended: "Отключён",
};

// drawer
const drawer = ref(null);
function openRow(row) {
    drawer.value = { ...row };
}
function saveDrawer() {
    const i = members.value.findIndex((m) => m.id === drawer.value.id);
    if (i > -1) members.value[i] = { ...drawer.value };
    toast.success("Сохранено", drawer.value.name);
    drawer.value = null;
}
function bulkSuspend() {
    members.value = members.value.map((m) =>
        selected.value.includes(m.id) ? { ...m, status: "suspended" } : m,
    );
    toast.warning("Отключено", selected.value.length + " участников");
    selected.value = [];
}
function bulkDelete() {
    members.value = members.value.filter((m) => !selected.value.includes(m.id));
    toast.error("Удалено", selected.value.length + " участников");
    selected.value = [];
}

// invite modal
const invite = ref(false);
const inviteEmail = ref("");
function submitInvite() {
    const em = inviteEmail.value.trim() || "new.user@nergous-cit.app";
    members.value.unshift({
        id: Date.now(),
        name: em.split("@")[0].replace(/\./g, " "),
        email: em,
        role: "Editor",
        status: "invited",
        plan: "Free",
        lastActive: "—",
    });
    invite.value = false;
    inviteEmail.value = "";
    toast.success("Приглашение отправлено", em);
}

// command palette
const commands = [
    {
        label: "Обзор",
        icon: "home",
        hint: "Раздел",
        action: () => (view.value = "overview"),
    },
    {
        label: "Участники",
        icon: "users",
        hint: "Раздел",
        action: () => (view.value = "members"),
    },
    {
        label: "Ассеты",
        icon: "asset",
        hint: "Раздел",
        action: () => (view.value = "assets"),
    },
    {
        label: "Настройки",
        icon: "settings",
        hint: "Раздел",
        action: () => (view.value = "settings"),
    },
    {
        label: "Пригласить участника",
        icon: "mail",
        hint: "Действие",
        action: () => {
            view.value = "members";
            invite.value = true;
        },
    },
    {
        label: "Переключить тему",
        icon: "moon",
        hint: "Действие",
        action: () => toggle(),
    },
];

const densityOpts = [
    { value: "compact", label: "S" },
    { value: "comfortable", label: "M" },
    { value: "spacious", label: "L" },
];

function onFiles(files) {
    toast.success("Загружено", files.length + " файл(ов) добавлено");
}
</script>

<template>
    <div class="cobalt-reset admin">
        <NToaster region-label="Уведомления" dismiss-label="Закрыть" />
        <NCommandPalette
            v-model="cmdOpen"
            :commands="commands"
            placeholder="Команды, переходы, действия…"
            empty-text="Ничего не найдено"
            nav-hint="навигация"
            select-hint="выбрать"
        />

        <NSidebar
            v-model="view"
            :groups="groups"
            :collapsed="collapsed"
            nav-label="Основная навигация"
        >
            <template #footer>
                <NButton
                    variant="ghost"
                    :icon="theme === 'dark' ? 'sun' : 'moon'"
                    block
                    @click="toggle"
                >
                    <span v-if="!collapsed">{{
                        theme === "dark" ? "Светлая тема" : "Тёмная тема"
                    }}</span>
                </NButton>
                <div v-if="!collapsed" class="admin__user">
                    <NAvatar name="Анна Кириллова" :size="30" />
                    <div class="admin__user-info">
                        <b>Анна Кириллова</b><span>Owner</span>
                    </div>
                </div>
            </template>
        </NSidebar>

        <div class="admin__main">
            <NTopbar
                :title="pageTitle"
                :subtitle="pageSub"
                toggle-label="Свернуть меню"
                @toggle="collapsed = !collapsed"
            >
                <button class="admin__cmd" @click="cmdOpen = true">
                    <NIcon name="search" :size="16" />
                    <span>Поиск и команды</span>
                    <span class="admin__kbd"><kbd>⌘</kbd><kbd>K</kbd></span>
                </button>
                <template #right>
                    <NSegmented
                        :model-value="density"
                        :options="densityOpts"
                        @update:model-value="setDensity"
                    />
                    <NButton
                        variant="secondary"
                        :icon="theme === 'dark' ? 'sun' : 'moon'"
                        @click="toggle"
                    />
                </template>
            </NTopbar>

            <main class="admin__body">
                <!-- OVERVIEW -->
                <template v-if="view === 'overview'">
                    <div class="admin__kpis">
                        <NStatCard v-for="(k, i) in kpis" :key="i" v-bind="k" />
                    </div>
                    <div class="admin__cta">
                        <div>
                            <h3>Готовы пригласить команду?</h3>
                            <p>
                                Добавьте участников и распределите роли за пару
                                минут.
                            </p>
                        </div>
                        <NButton
                            variant="primary"
                            icon="mail"
                            @click="
                                view = 'members';
                                invite = true;
                            "
                            >Пригласить</NButton
                        >
                    </div>
                </template>

                <!-- MEMBERS -->
                <template v-else-if="view === 'members'">
                    <div class="admin__toolbar">
                        <NInput
                            v-model="search"
                            icon="search"
                            placeholder="Поиск по имени, email, роли…"
                        />
                        <NButton
                            variant="primary"
                            icon="plus"
                            @click="invite = true"
                            >Пригласить</NButton
                        >
                    </div>
                    <NDataTable
                        :columns="columns"
                        :rows="filtered"
                        selectable
                        v-model:selected="selected"
                        :page-size="6"
                        :selection-label="(n) => `${n} выбрано`"
                        clear-label="Снять выделение"
                        :range-label="
                            (from, to, total) =>
                                `Показано ${from}–${to} из ${total}`
                        "
                        select-all-label="Выбрать все"
                        select-row-label="Выбрать строку"
                        @row-click="openRow"
                    >
                        <template #bulk>
                            <NButton
                                variant="secondary"
                                size="sm"
                                @click="bulkSuspend"
                                >Отключить</NButton
                            >
                            <NButton
                                variant="danger"
                                size="sm"
                                @click="bulkDelete"
                                >Удалить</NButton
                            >
                        </template>
                        <template #cell-name="{ row }">
                            <div class="admin__cell-user">
                                <NAvatar :name="row.name" :size="36" />
                                <div class="admin__cell-meta">
                                    <b>{{ row.name }}</b
                                    ><span>{{ row.email }}</span>
                                </div>
                            </div>
                        </template>
                        <template #cell-role="{ value }">
                            <NBadge :tone="roleTone[value]" pill>{{
                                value
                            }}</NBadge>
                        </template>
                        <template #cell-status="{ value }">
                            <NBadge :tone="statusTone[value]" dot>{{
                                statusLabel[value]
                            }}</NBadge>
                        </template>
                        <template #empty>
                            <NEmptyState
                                title="Никого не найдено"
                                description="Измените запрос или сбросьте фильтры"
                            />
                        </template>
                    </NDataTable>
                </template>

                <!-- ASSETS -->
                <template v-else-if="view === 'assets'">
                    <NDropzone @files="onFiles" />
                </template>

                <!-- SETTINGS -->
                <template v-else>
                    <NEmptyState
                        icon="settings"
                        title="Настройки"
                        description="Здесь будут параметры пространства — соберите их из form-компонентов библиотеки."
                    >
                        <NButton variant="secondary" @click="view = 'overview'"
                            >На главную</NButton
                        >
                    </NEmptyState>
                </template>
            </main>
        </div>

        <!-- invite modal -->
        <NModal
            v-model="invite"
            title="Пригласить участника"
            close-label="Закрыть"
        >
            <div class="admin__form">
                <NInput
                    v-model="inviteEmail"
                    icon="mail"
                    placeholder="name@company.com"
                />
                <NSelect
                    ><option>Editor</option>
                    <option>Admin</option>
                    <option>Viewer</option></NSelect
                >
            </div>
            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton variant="primary" block @click="submitInvite"
                    >Отправить</NButton
                >
            </template>
        </NModal>

        <!-- profile drawer -->
        <NDrawer
            :model-value="!!drawer"
            title="Профиль участника"
            close-label="Закрыть"
            @update:model-value="drawer = null"
        >
            <template v-if="drawer">
                <div class="admin__drawer-head">
                    <NAvatar :name="drawer.name" :size="52" />
                    <div>
                        <b>{{ drawer.name }}</b
                        ><span>{{ drawer.email }}</span>
                    </div>
                </div>
                <div class="admin__form">
                    <label>Имя<NInput v-model="drawer.name" /></label>
                    <label>Email<NInput v-model="drawer.email" /></label>
                    <label
                        >Роль
                        <NSelect v-model="drawer.role"
                            ><option>Owner</option>
                            <option>Admin</option>
                            <option>Editor</option>
                            <option>Viewer</option></NSelect
                        >
                    </label>
                </div>
            </template>
            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton variant="primary" block @click="saveDrawer"
                    >Сохранить</NButton
                >
            </template>
        </NDrawer>
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
.admin__main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}
.admin__body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
}
.admin__user {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 46px;
    padding: 0 8px;
    margin-top: 6px;
    border-radius: 10px;
    background: var(--surface-3);
}
.admin__user-info {
    line-height: 1.15;
}
.admin__user-info b {
    display: block;
    font-size: 13px;
    color: var(--text);
}
.admin__user-info span {
    font-size: 11.5px;
    color: var(--text-3);
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
    min-width: 230px;
}
.admin__cmd:hover {
    background: var(--surface-3);
}
.admin__cmd span {
    flex: 1;
    text-align: left;
}
.admin__kbd {
    display: flex;
    gap: 3px;
    flex: none;
}
.admin__kbd kbd,
.admin__cmd kbd {
    font-family: inherit;
    font-size: 11px;
    font-weight: 700;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 5px;
    padding: 1px 5px;
    color: var(--text-2);
}
.admin__kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.admin__cta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-top: 16px;
    padding: 22px;
    border-radius: var(--radius-lg);
    background: linear-gradient(150deg, var(--accent), #7a7ff7);
    color: #fff;
    box-shadow: 0 8px 24px -8px var(--accent);
}
.admin__cta h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 800;
    letter-spacing: -0.02em;
}
.admin__cta p {
    margin: 4px 0 0;
    font-size: 13.5px;
    opacity: 0.88;
}
.admin__toolbar {
    display: flex;
    gap: 10px;
    margin-bottom: 14px;
}
.admin__toolbar > :first-child {
    flex: 1;
}
.admin__cell-user {
    display: flex;
    align-items: center;
    gap: 11px;
}
.admin__cell-meta {
    line-height: 1.25;
    min-width: 0;
}
.admin__cell-meta b {
    display: block;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text);
}
.admin__cell-meta span {
    font-size: 12px;
    color: var(--text-3);
    font-family: var(--font-mono);
}
.admin__form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.admin__form label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-2);
}
.admin__drawer-head {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 22px;
}
.admin__drawer-head b {
    display: block;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--text);
}
.admin__drawer-head span {
    font-size: 13px;
    color: var(--text-3);
    font-family: var(--font-mono);
}
@media (max-width: 880px) {
    .admin__kpis {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
