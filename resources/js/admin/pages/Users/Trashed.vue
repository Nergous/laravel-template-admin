<script setup lang="ts">
import { ref } from "vue";
import type { PropType } from "vue";
import { router } from "@inertiajs/vue3";
import { usePageHeader } from "@/admin/composables/usePageHeader";
import {
    NDataTable,
    NButton,
    NEmptyState,
    NInput,
    NConfirmDialog,
} from "nergous-ui-vue";
import type { Column } from "nergous-ui-vue";
import type { AdminUser, Pagination } from "@/admin/types";
import AdminPagination from "@/admin/components/AdminPagination.vue";
import { useBulkSelection } from "@/admin/composables/useBulkSelection";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
import { tableRow } from "@/admin/composables/useTableRow";
import { formatDateTime } from "@/lib/format";
import { listUrl } from "@/lib/listUrl";

const props = defineProps({
    users: { type: Object as PropType<Pagination<AdminUser>>, required: true },
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
        type: Object as PropType<{ search?: string }>,
        default: () => ({}),
    },
});

const search = ref(props.filters.search ?? "");
const { reload, onSearch, onSort } = useIndexFilters(
    "/admin/users/trashed",
    () => ({
        search: search.value,
        sort: props.currentSort,
        direction: props.currentDirection,
        per_page: props.perPage,
    }),
);

// Filters sent with "all matching" bulk actions (the index query parameters).
function filterParams(): Record<string, string> {
    const searchValue = search.value.trim();
    return searchValue ? { search: searchValue } : {};
}

const {
    selected,
    allMatchingSelected,
    selectedCount,
    updateSelected,
    clearSelection,
    selectionPayload,
} = useBulkSelection(
    () => props.users.data.map((user) => user.id),
    () => props.users.total,
    filterParams,
);

const columns: Column[] = [
    { key: "name", label: "Имя", sortable: true },
    { key: "email", label: "Email" },
    { key: "deleted_at", label: "Удалён", width: "180px", sortable: true },
    { key: "actions", label: "", width: "200px", align: "right" },
];
const userRow = tableRow<AdminUser>();

function reloadPage(page: number, perPage = props.perPage) {
    reload({ page, per_page: perPage });
}

function restoreOne(id: number) {
    router.patch(`/admin/users/restore/${id}`, {}, { preserveScroll: true });
}
function bulkRestore() {
    router.post("/admin/users/trashed/bulk-restore", selectionPayload(), {
        preserveScroll: true,
        onSuccess: clearSelection,
    });
}

const forceConfirm = ref(false);
const forceLoading = ref(false);
const forceOneId = ref<number | null>(null); // null = bulk force
function askForceOne(id: number) {
    forceOneId.value = id;
    forceConfirm.value = true;
}
function askForceBulk() {
    forceOneId.value = null;
    forceConfirm.value = true;
}
function confirmForce() {
    forceLoading.value = true;
    const done = () => {
        forceConfirm.value = false;
        forceLoading.value = false;
    };
    if (forceOneId.value !== null) {
        router.delete(`/admin/users/force/${forceOneId.value}`, {
            preserveScroll: true,
            onFinish: done,
        });
    } else {
        router.delete("/admin/users/trashed/bulk-force", {
            data: selectionPayload(),
            preserveScroll: true,
            onSuccess: clearSelection,
            onFinish: done,
        });
    }
}

usePageHeader(() => ({
    title: "Корзина пользователей",
    subtitle: "Удалённые пользователи",
    crumbs: [{ label: "Пользователи", href: listUrl("/admin/users") }],
}));
</script>

<template>
    <div class="page">
        <div class="page__head">
            <div class="page__search">
                <NInput
                    v-model="search"
                    icon="search"
                    placeholder="Поиск по имени или email…"
                    aria-label="Поиск в корзине"
                    @update:model-value="onSearch"
                />
            </div>
        </div>

        <NDataTable
            :columns="columns"
            :rows="users.data"
            :page-size="0"
            selectable
            :selected="selected"
            select-all-label="Выбрать все"
            select-row-label="Выбрать строку"
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
                    variant="secondary"
                    size="sm"
                    icon="upload"
                    @click="bulkRestore"
                    >Восстановить</NButton
                >
                <NButton
                    variant="danger"
                    size="sm"
                    icon="trash"
                    @click="askForceBulk"
                    >Удалить навсегда</NButton
                >
            </template>
            <template #cell-deleted_at="{ row }">{{
                formatDateTime(userRow(row).deleted_at)
            }}</template>
            <template #cell-actions="{ row }">
                <div
                    v-if="userRow(row).can_manage"
                    class="row-actions row-actions--end"
                >
                    <NButton
                        variant="ghost"
                        size="sm"
                        icon="upload"
                        @click="restoreOne(userRow(row).id)"
                        >Восстановить</NButton
                    >
                    <NButton
                        variant="ghost"
                        tone="danger"
                        size="sm"
                        icon="trash"
                        aria-label="Удалить навсегда"
                        @click="askForceOne(userRow(row).id)"
                    />
                </div>
                <span v-else class="muted">Нет прав</span>
            </template>
            <template #empty>
                <NEmptyState
                    icon="trash"
                    title="Корзина пуста"
                    description="Удалённые пользователи появятся здесь."
                />
            </template>
        </NDataTable>

        <AdminPagination
            :paginator="users"
            :per-page="perPage"
            :per-page-options="perPageOptions"
            @update:page="reloadPage"
            @update:page-size="(size) => reloadPage(1, size)"
        />
    </div>

    <NConfirmDialog
        v-model="forceConfirm"
        title="Удалить навсегда"
        :message="
            forceOneId !== null
                ? 'Безвозвратно удалить пользователя? Действие необратимо.'
                : `Безвозвратно удалить выбранных пользователей (${selectedCount})? Действие необратимо.`
        "
        confirm-label="Удалить навсегда"
        :loading="forceLoading"
        @confirm="confirmForce"
        danger
    />
</template>

<style scoped>
.page__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.page__search {
    flex: 0 1 320px;
    min-width: 220px;
}
.muted {
    color: var(--text-3);
    font-size: 13px;
}
</style>
