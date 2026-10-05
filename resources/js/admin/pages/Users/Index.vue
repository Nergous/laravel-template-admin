<script setup lang="ts">
import { computed, ref } from "vue";
import type { PropType } from "vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import { usePageHeader } from "@/admin/composables/usePageHeader";
import {
    NDataTable,
    NButton,
    NInput,
    NBadge,
    NAvatar,
    NCheckbox,
    NEmptyState,
    NFormField,
    NToolbar,
    NSelect,
    NMultiSelect,
    NFilterChips,
    NConfirmDialog,
    NColumnPicker,
    useColumnVisibility,
} from "nergous-ui-vue";
import type { Column } from "nergous-ui-vue";
import type { AdminUser, Pagination, SharedProps } from "@/admin/types";
import AdminPagination from "@/admin/components/AdminPagination.vue";
import ExportMenu from "@/admin/components/ExportMenu.vue";
import { useBulkSelection } from "@/admin/composables/useBulkSelection";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
import { useListDelete } from "@/admin/composables/useListDelete";
import { tableRow } from "@/admin/composables/useTableRow";
import { can } from "@/lib/can";
import {
    chipTarget,
    joinList,
    listChips,
    parseList,
    without,
    type FilterChip,
} from "@/lib/filterList";
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
    exportColumns: {
        type: Array as PropType<{ key: string; label: string }[]>,
        default: () => [],
    },
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
            role?: string | string[];
            status?: string;
            must_change_password?: string;
        }>,
        default: () => ({}),
    },
});

const search = ref(props.filters.search ?? "");
const page = usePage<SharedProps>();
// Several roles: comma-separated in the address, any of them matches.
const role = ref(parseList(props.filters.role));
const status = ref(props.filters.status ?? "");
const mustChangePassword = ref(
    ["1", "true"].includes(props.filters.must_change_password ?? ""),
);

const roleOptions = computed(() => [
    { value: props.withoutRolesValue, label: "Без роли" },
    ...Object.entries(props.roles).map(([value, label]) => ({ value, label })),
]);

const statusOptions = [
    { value: "", label: "Все статусы" },
    { value: "active", label: "Активные" },
    { value: "blocked", label: "Заблокированные" },
];

const optionLabel = (
    options: { value: string; label: string }[],
    value: string,
) => options.find((option) => option.value === value)?.label ?? value;
const activeFilters = computed(() => {
    const list: FilterChip[] = [];
    const term = search.value.trim();
    if (term) list.push({ key: "search", label: "Поиск", value: term });
    list.push(...listChips("role", "Роль", role.value, roleOptions.value));
    if (status.value)
        list.push({
            key: "status",
            label: "Статус",
            value: optionLabel(statusOptions, status.value),
        });
    if (mustChangePassword.value)
        list.push({
            key: "password",
            label: "Смена пароля",
            value: "требуется",
        });
    return list;
});
function removeFilter(key: string) {
    const { name, value } = chipTarget(key);
    if (name === "search") search.value = "";
    if (name === "role" && value !== null)
        role.value = without(role.value, value);
    if (name === "status") status.value = "";
    if (name === "password") mustChangePassword.value = false;
    reload({ page: 1 });
}
function resetFilters() {
    search.value = "";
    role.value = [];
    status.value = "";
    mustChangePassword.value = false;
    reload({ page: 1 });
}

// Filters shared by the list reload, the CSV export, and "all matching" bulk actions.
function filterParams(): Record<string, string> {
    const params: Record<string, string> = {};
    const currentSearch = search.value.trim();
    if (currentSearch) params.search = currentSearch;
    const roles = joinList(role.value);
    if (roles) params.role = roles;
    if (status.value) params.status = status.value;
    if (mustChangePassword.value) params.must_change_password = "1";
    return params;
}

const { reload, onSearch, onSort } = useIndexFilters("/admin/users", () => ({
    search: search.value,
    role: joinList(role.value),
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
const selection = useBulkSelection(
    () => props.users.data.map((user) => user.id),
    () => props.users.total,
    filterParams,
);
const {
    selected,
    allMatchingSelected,
    selectedCount,
    updateSelected,
    clearSelection,
    selectionPayload,
} = selection;

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

const { columns, columnChoices, hiddenColumns } = useColumnVisibility(
    allColumns,
    ["email", "roles", "last_login_at", "created_at"],
    "admin-users-hidden-columns",
);

const userRow = tableRow<AdminUser>();
const {
    del,
    confirmDelete,
    bulkOpen,
    bulkLoading,
    askBulkDelete,
    confirmBulkDelete,
} = useListDelete<AdminUser>("/admin/users", selection);

// null = closed; true = unblock, false = block.
const statusAction = ref<boolean | null>(null);
const statusLoading = ref(false);
const blockReason = ref("");

function askBulkStatus(active: boolean) {
    if (selectedCount.value === 0) return;
    blockReason.value = "";
    statusAction.value = active;
}

// The status dialog is open while an action is chosen; closing it drops the choice.
const statusOpen = computed({
    get: () => statusAction.value !== null,
    set: (open: boolean) => {
        if (!open) statusAction.value = null;
    },
});

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

usePageHeader(() => ({
    title: "Пользователи",
    subtitle: `${props.users.total} учётных записей`,
}));
</script>

<template>
    <div class="page">
        <NToolbar>
            <template #search>
                <NInput
                    v-model="search"
                    icon="search"
                    placeholder="Поиск по имени или email…"
                    aria-label="Поиск по имени или email"
                    @update:model-value="onSearch"
                />
            </template>
            <NMultiSelect
                v-model="role"
                :options="roleOptions"
                label="Роль"
                placeholder="Все роли"
                @update:model-value="reload({ page: 1 })"
            />
            <NSelect
                v-model="status"
                :options="statusOptions"
                aria-label="Фильтр по статусу"
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
            <ExportMenu
                v-if="can('users.export')"
                :url="exportUrl"
                :columns="exportColumns"
                storage-key="admin-users-export"
                :disabled="users.total === 0"
            />
            <NColumnPicker
                v-model:hidden="hiddenColumns"
                :columns="columnChoices"
            />
            <template #actions>
                <NButton
                    v-if="can('users.create')"
                    :as="Link"
                    href="/admin/users/create"
                    variant="primary"
                    icon="plus"
                    >Добавить</NButton
                >
            </template>
        </NToolbar>

        <NFilterChips
            :filters="activeFilters"
            @remove="removeFilter"
            @reset="resetFilters"
        />

        <NDataTable
            :columns="columns"
            :rows="rows"
            :page-size="0"
            :selectable="canBulkDelete || canBulkStatus"
            :selected="selected"
            select-all-label="Выбрать текущую страницу"
            select-row-label="Выбрать пользователя"
            manual-sort
            :sort-key="currentSort"
            :sort-dir="currentDirection"
            @update:selected="updateSelected"
            @sort-change="onSort"
            stacked
            :total="users.total"
            v-model:all-matching="allMatchingSelected"
        >
            <template #bulk>
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

        <AdminPagination
            :paginator="users"
            :per-page="perPage"
            :per-page-options="perPageOptions"
            @update:page="(p) => reload({ page: p })"
            @update:page-size="(size) => reload({ per_page: size, page: 1 })"
        />
    </div>

    <NConfirmDialog
        v-model="del.open"
        :loading="del.loading"
        :message="`Отправить пользователя «${del.payload?.name}» в корзину?`"
        confirm-label="В корзину"
        @confirm="confirmDelete"
        danger
    />

    <NConfirmDialog
        v-model="bulkOpen"
        title="Переместить в корзину"
        :message="`Переместить выбранных пользователей (${selectedCount}) в корзину?`"
        confirm-label="В корзину"
        :loading="bulkLoading"
        @confirm="confirmBulkDelete"
        danger
    />

    <NConfirmDialog
        v-model="statusOpen"
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
    </NConfirmDialog>
</template>

<style scoped>
.block-reason {
    margin-top: 14px;
}
.toolbar__trash {
    text-decoration: none;
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
