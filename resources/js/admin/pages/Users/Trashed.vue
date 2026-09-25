<script setup lang="ts">
import { computed, ref } from "vue";
import type { PropType } from "vue";
import { Link, router } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NDataTable,
    NPagination,
    NButton,
    NEmptyState,
    NInput,
} from "nergous-ui-vue";
import type { Column, Row } from "nergous-ui-vue";
import type { AdminUser, Pagination } from "@/admin/types";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
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

function selectionPayload():
    { ids: number[] } | { all: true; search?: string } {
    if (!allMatchingSelected.value) return { ids: selected.value };

    const search = props.filters.search?.trim();
    return search ? { all: true, search } : { all: true };
}

const columns: Column[] = [
    { key: "name", label: "Имя", sortable: true },
    { key: "email", label: "Email" },
    { key: "deleted_at", label: "Удалён", width: "180px", sortable: true },
    { key: "actions", label: "", width: "200px", align: "right" },
];
const userRow = (row: Row): AdminUser => row as AdminUser;

// Keep the pager visible while a smaller page size could still split the list.
const showPager = computed(
    () =>
        props.users.last_page > 1 ||
        props.users.total > Math.min(...props.perPageOptions),
);

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
</script>

<template>
    <AdminLayout
        title="Корзина пользователей"
        subtitle="Удалённые пользователи"
    >
        <div class="page">
            <div class="page__head">
                <Link :href="listUrl('/admin/users')" class="page__back"
                    >← К списку</Link
                >
                <div class="page__search">
                    <NInput
                        v-model="search"
                        icon="search"
                        placeholder="Поиск по имени или email…"
                        aria-label="Поиск в корзине"
                        @update:model-value="
                            () => {
                                clearSelection();
                                onSearch();
                            }
                        "
                    />
                </div>
            </div>

            <NDataTable
                :columns="columns"
                :rows="users.data"
                :page-size="0"
                selectable
                :selected="tableSelected"
                :selection-label="selectionLabel"
                clear-label="Снять выделение"
                select-all-label="Выбрать все"
                select-row-label="Выбрать строку"
                manual-sort
                :sort-key="currentSort"
                :sort-dir="currentDirection"
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
                    @update:page="reloadPage"
                    @update:page-size="(size) => reloadPage(1, size)"
                />
            </div>
        </div>

        <ConfirmModal
            :open="forceConfirm"
            title="Удалить навсегда"
            :message="
                forceOneId !== null
                    ? 'Безвозвратно удалить пользователя? Действие необратимо.'
                    : `Безвозвратно удалить выбранных пользователей (${selectedCount})? Действие необратимо.`
            "
            confirm-label="Удалить навсегда"
            :loading="forceLoading"
            @confirm="confirmForce"
            @cancel="forceConfirm = false"
            @update:open="forceConfirm = $event"
        />
    </AdminLayout>
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
.page__back {
    color: var(--text-2);
    font-weight: 600;
    font-size: 13.5px;
    text-decoration: none;
}
.muted {
    color: var(--text-3);
    font-size: 13px;
}
</style>
