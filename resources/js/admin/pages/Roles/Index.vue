<script setup lang="ts">
import { ref } from "vue";
import type { PropType } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { usePageHeader } from "@/admin/composables/usePageHeader";
import {
    NBadge,
    NButton,
    NDataTable,
    NEmptyState,
    NInput,
    NToolbar,
    NConfirmDialog,
    NColumnPicker,
    useConfirm,
    useColumnVisibility,
} from "nergous-ui-vue";
import type { Column } from "nergous-ui-vue";
import type { AdminRole, Pagination } from "@/admin/types";
import AdminPagination from "@/admin/components/AdminPagination.vue";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
import { tableRow } from "@/admin/composables/useTableRow";
import { can } from "@/lib/can";
import { formatDateShort, formatNumber } from "@/lib/format";
import { swatchColor } from "@/lib/swatch";

const props = defineProps({
    roles: { type: Object as PropType<Pagination<AdminRole>>, required: true },
    permissionsTotal: { type: Number, default: 0 },
    filters: {
        type: Object as PropType<{ search?: string }>,
        default: () => ({}),
    },
    currentSort: { type: String, default: "name" },
    currentDirection: {
        type: String as PropType<"asc" | "desc">,
        default: "asc",
    },
    perPage: { type: Number, default: 10 },
    perPageOptions: {
        type: Array as PropType<number[]>,
        default: () => [10, 25, 50, 100],
    },
});

const search = ref(props.filters.search ?? "");
const { reload, onSearch, onSort } = useIndexFilters("/admin/roles", () => ({
    search: search.value,
    sort: props.currentSort,
    direction: props.currentDirection,
    per_page: props.perPage,
}));

const allColumns: Column[] = [
    { key: "name", label: "Роль", sortable: true },
    { key: "description", label: "Описание" },
    { key: "is_system", label: "Тип", sortable: true, width: "140px" },
    {
        key: "users_count",
        label: "Пользователи",
        sortable: true,
        width: "140px",
        align: "center",
    },
    {
        key: "permissions_count",
        label: "Разрешения",
        sortable: true,
        width: "130px",
        align: "center",
    },
    { key: "created_at", label: "Добавлена", sortable: true, width: "140px" },
    { key: "actions", label: "Действия", width: "120px", align: "center" },
];

const { columns, columnChoices, hiddenColumns } = useColumnVisibility(
    allColumns,
    [
        "description",
        "is_system",
        "users_count",
        "permissions_count",
        "created_at",
    ],
    "admin-roles-hidden-columns",
);

const roleRow = tableRow<AdminRole>();
const del = useConfirm<AdminRole>();

function confirmDelete() {
    if (!del.payload) return;
    del.loading = true;
    router.delete(`/admin/roles/${del.payload.id}`, {
        preserveScroll: true,
        onFinish: () => del.close(),
    });
}

usePageHeader(() => ({
    title: "Роли",
    subtitle: `${props.roles.total} ролей`,
}));
</script>

<template>
    <div class="page">
        <NToolbar>
            <template #search>
                <NInput
                    v-model="search"
                    icon="search"
                    placeholder="Поиск по названию или описанию…"
                    aria-label="Поиск ролей"
                    @update:model-value="onSearch"
                />
            </template>
            <NColumnPicker
                v-model:hidden="hiddenColumns"
                :columns="columnChoices"
            />
            <template #actions>
                <NButton
                    v-if="can('roles.create')"
                    :as="Link"
                    href="/admin/roles/create"
                    variant="primary"
                    icon="plus"
                    >Добавить</NButton
                >
            </template>
        </NToolbar>

        <NDataTable
            :columns="columns"
            :rows="roles.data"
            :page-size="0"
            manual-sort
            :sort-key="currentSort"
            :sort-dir="currentDirection"
            @sort-change="onSort"
            stacked
        >
            <template #cell-name="{ row }">
                <div class="rcell">
                    <span
                        class="rcell__swatch"
                        :style="{
                            background: swatchColor(roleRow(row).name),
                        }"
                    />
                    <Link
                        :href="`/admin/roles/${roleRow(row).id}`"
                        class="role-name"
                        >{{ roleRow(row).name }}</Link
                    >
                </div>
            </template>

            <template #cell-description="{ row }">
                <span v-if="roleRow(row).description" class="desc-cell">{{
                    roleRow(row).description
                }}</span>
                <span v-else class="muted">—</span>
            </template>

            <template #cell-is_system="{ row }">
                <NBadge
                    :tone="roleRow(row).is_system ? 'accent' : 'neutral'"
                    pill
                    >{{
                        roleRow(row).is_system ? "системная" : "кастомная"
                    }}</NBadge
                >
            </template>

            <template #cell-users_count="{ row }">
                {{ formatNumber(roleRow(row).users_count ?? 0) }}
            </template>

            <template #cell-permissions_count="{ row }">
                {{ formatNumber(roleRow(row).permissions_count ?? 0) }}
                <span class="muted">/ {{ permissionsTotal }}</span>
            </template>

            <template #cell-created_at="{ row }">
                <span class="created">{{
                    roleRow(row).created_at
                        ? formatDateShort(roleRow(row).created_at)
                        : "—"
                }}</span>
            </template>

            <template #cell-actions="{ row }">
                <div class="row-actions row-actions--center">
                    <NButton
                        :as="Link"
                        :href="`/admin/roles/${roleRow(row).id}`"
                        variant="ghost"
                        icon="eye"
                        size="sm"
                        class="row-actions__btn"
                        :aria-label="`Открыть роль ${roleRow(row).name}`"
                    />
                    <NButton
                        v-if="roleRow(row).can_edit"
                        :as="Link"
                        :href="`/admin/roles/${roleRow(row).id}/edit`"
                        variant="ghost"
                        tone="accent"
                        icon="edit"
                        size="sm"
                        class="row-actions__btn"
                        :aria-label="`Редактировать роль ${roleRow(row).name}`"
                    />
                    <NButton
                        v-if="can('roles.delete') && !roleRow(row).is_system"
                        variant="ghost"
                        tone="danger"
                        icon="trash"
                        size="sm"
                        class="row-actions__btn"
                        aria-label="Удалить роль"
                        @click="del.ask(roleRow(row))"
                    />
                </div>
            </template>

            <template #empty>
                <NEmptyState
                    icon="shield"
                    title="Роли не найдены"
                    description="Измените условия поиска или добавьте новую роль."
                />
            </template>
        </NDataTable>

        <AdminPagination
            :paginator="roles"
            :per-page="perPage"
            :per-page-options="perPageOptions"
            @update:page="(p) => reload({ page: p })"
            @update:page-size="(size) => reload({ per_page: size, page: 1 })"
        />
    </div>

    <NConfirmDialog
        v-model="del.open"
        :loading="del.loading"
        :message="`Удалить роль «${del.payload?.name}»? Пользователи потеряют связанные права.`"
        @confirm="confirmDelete"
        danger
        confirm-label="Удалить"
    />
</template>

<style scoped>
.rcell {
    display: flex;
    align-items: center;
    gap: 10px;
}
.rcell__swatch {
    width: 11px;
    height: 11px;
    border-radius: 4px;
    flex: none;
}
.role-name {
    font-weight: 700;
    font-size: 13.5px;
    color: var(--text);
    text-decoration: none;
}
.role-name:hover {
    color: var(--accent);
}
.role-name:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.role-name--plain:hover {
    color: var(--text);
}
.desc-cell {
    color: var(--text-2);
    font-size: 13px;
}
.created {
    color: var(--text-2);
    font-size: 13px;
}
.muted {
    color: var(--text-3);
}
</style>
