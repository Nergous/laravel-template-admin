<script setup lang="ts">
import { computed, ref, watch } from "vue";
import type { PropType } from "vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NDataTable,
    NPagination,
    NButton,
    NInput,
    NSelect,
    NBadge,
    NAvatar,
    NCheckbox,
    NEmptyState,
    NFormField,
} from "nergous-ui-vue";
import type { Column, Row } from "nergous-ui-vue";
import type { AdminUser, Pagination, SharedProps } from "@/admin/types";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { useConfirm } from "@/admin/composables/useConfirm";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
import { can } from "@/lib/can";
import { formatDateShort } from "@/lib/format";
import { swatchColor } from "@/lib/swatch";

const props = defineProps({
    users: { type: Object as PropType<Pagination<AdminUser>>, required: true },
    roles: {
        type: Object as PropType<Record<string, string>>,
        default: () => ({}),
    }, // { admin:'admin', ... }
    withoutRolesValue: { type: String, default: "__none" },
    trashedCount: { type: Number, default: 0 },
    currentSort: { type: String, default: "id" },
    currentDirection: {
        type: String as PropType<"asc" | "desc">,
        default: "desc",
    },
    perPage: { type: Number, default: 10 },
    perPageOptions: {
        type: Array as PropType<number[]>,
        default: () => [10, 25, 50, 100],
    },
    filters: {
        type: Object as PropType<{
            search?: string;
            role?: string;
            status?: string;
            must_change_password?: string;
        }>,
        default: () => ({}),
    },
});

const search = ref(props.filters.search ?? "");
const page = usePage<SharedProps>();
const role = ref(props.filters.role ?? "");
const status = ref(props.filters.status ?? "");
const mustChangePassword = ref(
    ["1", "true"].includes(props.filters.must_change_password ?? ""),
);

const roleOptions = computed(() => [
    { value: "", label: "Все роли" },
    { value: props.withoutRolesValue, label: "Без роли" },
    ...Object.entries(props.roles).map(([value, label]) => ({ value, label })),
]);

const statusOptions = [
    { value: "", label: "Все статусы" },
    { value: "active", label: "Активные" },
    { value: "blocked", label: "Заблокированные" },
];

// Filters shared by the list reload, the CSV export, and "all matching" bulk actions.
function filterParams(): Record<string, string> {
    const params: Record<string, string> = {};
    const currentSearch = search.value.trim();
    if (currentSearch) params.search = currentSearch;
    if (role.value) params.role = role.value;
    if (status.value) params.status = status.value;
    if (mustChangePassword.value) params.must_change_password = "1";
    return params;
}

const { reload, onSearch, onSort } = useIndexFilters("/admin/users", () => ({
    search: search.value,
    role: role.value,
    status: status.value || undefined,
    must_change_password: mustChangePassword.value ? 1 : undefined,
    sort: props.currentSort,
    direction: props.currentDirection,
    per_page: props.perPage,
}));

const exportUrl = computed(() => {
    const query = new URLSearchParams({
        ...filterParams(),
        sort: props.currentSort,
        direction: props.currentDirection,
    }).toString();
    return `/admin/users/export?${query}`;
});

const rows = computed(() => props.users.data);
// Keep the pager visible while a smaller page size could still split the list.
const showPager = computed(
    () =>
        props.users.last_page > 1 ||
        props.users.total > Math.min(...props.perPageOptions),
);
const selected = ref<number[]>([]);
const allMatchingSelected = ref(false);
const tableSelected = computed(() =>
    allMatchingSelected.value
        ? props.users.data.map((user) => user.id)
        : selected.value,
);
const selectedCount = computed(() =>
    allMatchingSelected.value ? props.users.total : selected.value.length,
);

function updateSelected(ids: Array<string | number>) {
    allMatchingSelected.value = false;
    selected.value = ids.map(Number);
}

function selectAllMatching() {
    allMatchingSelected.value = true;
}

function clearSelection() {
    selected.value = [];
    allMatchingSelected.value = false;
}

function selectionLabel() {
    return `${selectedCount.value} выбрано`;
}

function selectionPayload(): { ids: number[] } | Record<string, string | true> {
    if (!allMatchingSelected.value) return { ids: selected.value };
    return { all: true, ...filterParams() };
}

watch([search, role, status, mustChangePassword], clearSelection);

const canBulkDelete = computed(() => can("users.delete"));
const canBulkStatus = computed(() => can("users.edit"));

const allColumns: Column[] = [
    { key: "name", label: "Пользователь", sortable: true },
    { key: "email", label: "Email" },
    { key: "roles", label: "Роли" },
    {
        key: "last_login_at",
        label: "Последний вход",
        sortable: true,
        width: "150px",
    },
    { key: "created_at", label: "Добавлен", sortable: true, width: "140px" },
    { key: "actions", label: "Действия", width: "120px", align: "center" },
];

// Optional columns the user can hide; the choice is remembered in this browser.
const OPTIONAL_COLUMNS = ["email", "roles", "last_login_at", "created_at"];
const COLUMNS_KEY = "admin-users-hidden-columns";
function readHiddenColumns(): string[] {
    try {
        const stored = JSON.parse(localStorage.getItem(COLUMNS_KEY) ?? "[]");
        return Array.isArray(stored)
            ? stored.filter((key) => OPTIONAL_COLUMNS.includes(key))
            : [];
    } catch {
        return [];
    }
}
const hiddenColumns = ref<string[]>(readHiddenColumns());
watch(hiddenColumns, (keys) => {
    try {
        localStorage.setItem(COLUMNS_KEY, JSON.stringify(keys));
    } catch {
        // Storage unavailable: the choice lasts until the page is reloaded.
    }
});
const columnChoices = allColumns.filter((c) =>
    OPTIONAL_COLUMNS.includes(c.key),
);
function toggleColumn(key: string, shown: boolean) {
    hiddenColumns.value = shown
        ? hiddenColumns.value.filter((k) => k !== key)
        : [...hiddenColumns.value, key];
}
const columns = computed(() =>
    allColumns.filter((c) => !hiddenColumns.value.includes(c.key)),
);

const del = useConfirm();
const userRow = (row: Row): AdminUser => row as AdminUser;

function confirmDelete() {
    if (!del.payload) return;
    del.loading = true;
    router.delete(`/admin/users/${del.payload.id}`, {
        preserveScroll: true,
        onFinish: () => del.close(),
    });
}

const bulkOpen = ref(false);
const bulkLoading = ref(false);

function askBulkDelete() {
    if (selectedCount.value > 0) bulkOpen.value = true;
}

function confirmBulkDelete() {
    bulkLoading.value = true;
    router.delete("/admin/users/bulk", {
        data: selectionPayload(),
        preserveScroll: true,
        onSuccess: clearSelection,
        onFinish: () => {
            bulkLoading.value = false;
            bulkOpen.value = false;
        },
    });
}

// null = closed; true = unblock, false = block.
const statusAction = ref<boolean | null>(null);
const statusLoading = ref(false);
const blockReason = ref("");

function askBulkStatus(active: boolean) {
    if (selectedCount.value === 0) return;
    blockReason.value = "";
    statusAction.value = active;
}

function closeBulkStatus() {
    statusAction.value = null;
}

function confirmBulkStatus() {
    if (statusAction.value === null) return;
    statusLoading.value = true;
    router.patch(
        "/admin/users/bulk-status",
        {
            ...selectionPayload(),
            active: statusAction.value,
            reason: statusAction.value ? undefined : blockReason.value.trim(),
        },
        {
            preserveScroll: true,
            onSuccess: clearSelection,
            onFinish: () => {
                statusLoading.value = false;
                statusAction.value = null;
            },
        },
    );
}
</script>

<template>
    <AdminLayout
        title="Пользователи"
        :subtitle="`${users.total} учётных записей`"
    >
        <div class="page">
            <div class="toolbar">
                <div class="toolbar__search">
                    <NInput
                        v-model="search"
                        icon="search"
                        placeholder="Поиск по имени или email…"
                        aria-label="Поиск по имени или email"
                        @update:model-value="onSearch"
                    />
                </div>
                <NSelect
                    v-model="role"
                    :options="roleOptions"
                    aria-label="Фильтр по роли"
                    class="toolbar__select"
                    @update:model-value="reload({ page: 1 })"
                />
                <NSelect
                    v-model="status"
                    :options="statusOptions"
                    aria-label="Фильтр по статусу"
                    class="toolbar__select"
                    @update:model-value="reload({ page: 1 })"
                />
                <NCheckbox
                    v-model="mustChangePassword"
                    @update:model-value="reload({ page: 1 })"
                    >Требуется смена пароля</NCheckbox
                >
                <NButton
                    v-if="trashedCount > 0 && can('users.delete')"
                    :as="Link"
                    href="/admin/users/trashed"
                    variant="secondary"
                    icon="trash"
                    class="toolbar__trash"
                    >Корзина · {{ trashedCount }}</NButton
                >
                <NButton
                    v-if="can('users.export')"
                    variant="secondary"
                    icon="download"
                    :as="'a'"
                    :href="exportUrl"
                    :disabled="users.total === 0"
                    >Экспорт CSV</NButton
                >
                <details class="colpick">
                    <summary class="colpick__toggle">Колонки</summary>
                    <div class="colpick__menu">
                        <NCheckbox
                            v-for="c in columnChoices"
                            :key="c.key"
                            :model-value="!hiddenColumns.includes(c.key)"
                            @update:model-value="
                                (v: boolean) => toggleColumn(c.key, v)
                            "
                            >{{ c.label }}</NCheckbox
                        >
                    </div>
                </details>
                <NButton
                    v-if="can('users.create')"
                    :as="Link"
                    href="/admin/users/create"
                    variant="primary"
                    icon="plus"
                    class="toolbar__add"
                    >Добавить</NButton
                >
            </div>

            <NDataTable
                :columns="columns"
                :rows="rows"
                :page-size="0"
                :hover="false"
                :selectable="canBulkDelete || canBulkStatus"
                :selected="tableSelected"
                :selection-label="selectionLabel"
                clear-label="Снять выделение"
                select-all-label="Выбрать текущую страницу"
                select-row-label="Выбрать пользователя"
                manual-sort
                :sort-key="currentSort"
                :sort-dir="currentDirection"
                empty-text="Нет данных"
                @update:selected="updateSelected"
                @sort-change="onSort"
            >
                <template #bulk>
                    <NButton
                        v-if="
                            !allMatchingSelected &&
                            users.total > selected.length
                        "
                        variant="ghost"
                        size="sm"
                        @click="selectAllMatching"
                    >
                        Выбрать все {{ users.total }}
                    </NButton>
                    <NButton
                        v-if="canBulkStatus"
                        variant="secondary"
                        size="sm"
                        icon="lock"
                        @click="askBulkStatus(false)"
                    >
                        Заблокировать
                    </NButton>
                    <NButton
                        v-if="canBulkStatus"
                        variant="secondary"
                        size="sm"
                        icon="check"
                        @click="askBulkStatus(true)"
                    >
                        Разблокировать
                    </NButton>
                    <NButton
                        v-if="canBulkDelete"
                        variant="danger"
                        size="sm"
                        icon="trash"
                        @click="askBulkDelete"
                    >
                        В корзину
                    </NButton>
                </template>
                <template #cell-name="{ row }">
                    <div class="ucell">
                        <NAvatar :name="userRow(row).name" :size="36" />
                        <Link
                            :href="`/admin/users/${userRow(row).id}`"
                            class="ucell__name"
                            >{{ userRow(row).name }}</Link
                        >
                        <NBadge
                            v-if="userRow(row).is_active === false"
                            tone="danger"
                            pill
                            >Заблокирован</NBadge
                        >
                    </div>
                </template>

                <template #cell-email="{ row }">
                    <span class="email-cell">{{ userRow(row).email }}</span>
                </template>

                <template #cell-roles="{ row }">
                    <span class="roles-cell">
                        <NBadge
                            v-for="r in userRow(row).roles"
                            :key="r.id"
                            tone="neutral"
                            pill
                            :swatch="swatchColor(r.name)"
                            >{{ r.name }}</NBadge
                        >
                        <span v-if="!userRow(row).roles?.length" class="muted"
                            >—</span
                        >
                    </span>
                </template>

                <template #cell-last_login_at="{ row }">
                    <span class="created">{{
                        userRow(row).last_login_at
                            ? formatDateShort(userRow(row).last_login_at)
                            : "никогда"
                    }}</span>
                </template>

                <template #cell-created_at="{ row }">
                    <span class="created">{{
                        formatDateShort(userRow(row).created_at)
                    }}</span>
                </template>

                <template #cell-actions="{ row }">
                    <div class="row-actions row-actions--center">
                        <NButton
                            :as="Link"
                            :href="`/admin/users/${userRow(row).id}`"
                            variant="ghost"
                            icon="eye"
                            size="sm"
                            class="row-actions__btn"
                            :aria-label="`Открыть пользователя ${userRow(row).name}`"
                        />
                        <NButton
                            v-if="can('users.edit') && userRow(row).can_manage"
                            :as="Link"
                            :href="`/admin/users/${userRow(row).id}/edit`"
                            variant="ghost"
                            tone="accent"
                            icon="edit"
                            size="sm"
                            class="row-actions__btn"
                            aria-label="Редактировать"
                        />
                        <NButton
                            v-if="
                                can('users.delete') &&
                                userRow(row).can_manage &&
                                userRow(row).id !== page.props.auth.user?.id
                            "
                            variant="ghost"
                            tone="danger"
                            icon="trash"
                            size="sm"
                            class="row-actions__btn"
                            aria-label="Удалить"
                            @click="del.ask(userRow(row))"
                        />
                    </div>
                </template>

                <template #empty>
                    <NEmptyState
                        icon="users"
                        title="Пользователи не найдены"
                        description="Измените условия поиска или добавьте нового пользователя."
                    />
                </template>
            </NDataTable>

            <div v-if="showPager" class="page__pager">
                <NPagination
                    :page="users.current_page"
                    :pages="users.last_page"
                    :page-size="perPage"
                    :page-sizes="perPageOptions"
                    page-size-label="Показывать по"
                    jumpable
                    prev-label="Назад"
                    next-label="Вперёд"
                    jump-label="Страница"
                    jump-button-label="Перейти"
                    total-label="из"
                    jump-error-label="Введите корректный номер страницы"
                    aria-label="Навигация по страницам"
                    @update:page="(p) => reload({ page: p })"
                    @update:page-size="
                        (size) => reload({ per_page: size, page: 1 })
                    "
                />
            </div>
        </div>

        <ConfirmModal
            :open="del.open"
            :loading="del.loading"
            :message="`Отправить пользователя «${del.payload?.name}» в корзину?`"
            confirm-label="В корзину"
            @confirm="confirmDelete"
            @cancel="del.close"
            @update:open="del.open = $event"
        />

        <ConfirmModal
            :open="bulkOpen"
            title="Переместить в корзину"
            :message="`Переместить выбранных пользователей (${selectedCount}) в корзину?`"
            confirm-label="В корзину"
            :loading="bulkLoading"
            @confirm="confirmBulkDelete"
            @cancel="bulkOpen = false"
            @update:open="bulkOpen = $event"
        />

        <ConfirmModal
            :open="statusAction !== null"
            :title="statusAction ? 'Разблокировать' : 'Заблокировать'"
            :message="
                statusAction
                    ? `Разблокировать выбранных пользователей (${selectedCount})?`
                    : `Заблокировать выбранных пользователей (${selectedCount})? Они не смогут войти в панель.`
            "
            :confirm-label="statusAction ? 'Разблокировать' : 'Заблокировать'"
            :danger="statusAction === false"
            :loading="statusLoading"
            @confirm="confirmBulkStatus"
            @cancel="closeBulkStatus"
            @update:open="closeBulkStatus"
        >
            <NFormField
                v-if="statusAction === false"
                label="Причина блокировки"
                hint="Необязательно. Пользователь увидит её при попытке входа."
                class="block-reason"
            >
                <NInput
                    v-model="blockReason"
                    maxlength="255"
                    placeholder="Например: увольнение"
                />
            </NFormField>
        </ConfirmModal>
    </AdminLayout>
</template>

<style scoped>
.block-reason {
    margin-top: 14px;
}
.colpick {
    position: relative;
}
.colpick__toggle {
    list-style: none;
    display: inline-flex;
    align-items: center;
    height: 38px;
    padding: 0 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface);
    color: var(--text-2);
    font-weight: 600;
    font-size: 13.5px;
    cursor: pointer;
}
.colpick__toggle::-webkit-details-marker {
    display: none;
}
.colpick__menu {
    position: absolute;
    z-index: 20;
    top: calc(100% + 6px);
    right: 0;
    display: grid;
    gap: 8px;
    min-width: 200px;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-lg, 0 8px 24px rgba(0, 0, 0, 0.15));
}
.toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.toolbar__search {
    flex: 1;
    min-width: 220px;
}
.toolbar__select {
    flex: none;
    min-width: 150px;
}
.toolbar__trash {
    text-decoration: none;
}
.toolbar__add {
    margin-left: auto;
}

.ucell {
    display: flex;
    align-items: center;
    gap: 11px;
}
.ucell__name {
    font-weight: 700;
    font-size: 13.5px;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-decoration: none;
}
.ucell__name:hover {
    color: var(--accent);
}
.ucell__name:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.email-cell {
    color: var(--text-2);
    font-size: 13px;
}

.roles-cell {
    display: inline-flex;
    gap: 5px;
    flex-wrap: wrap;
}
.created {
    color: var(--text-2);
    font-size: 13px;
}
.muted {
    color: var(--text-3);
}
</style>
