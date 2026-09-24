<script setup lang="ts">
import { ref } from "vue";
import type { PropType } from "vue";
import { Link, router } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import { NDataTable, NPagination, NButton, NEmptyState } from "nergous-ui-vue";
import type { Column, Row } from "nergous-ui-vue";
import type { AdminUser, Pagination } from "@/admin/types";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { formatDateTime } from "@/lib/format";

defineProps({
    users: { type: Object as PropType<Pagination<AdminUser>>, required: true },
});

const selected = ref<number[]>([]);

const columns: Column[] = [
    { key: "name", label: "Имя" },
    { key: "email", label: "Email" },
    { key: "deleted_at", label: "Удалён", width: "180px" },
    { key: "actions", label: "", width: "200px", align: "right" },
];
const userRow = (row: Row): AdminUser => row as AdminUser;

function reloadPage(p: number) {
    router.get(
        "/admin/users/trashed",
        { page: p },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function restoreOne(id: number) {
    router.patch(`/admin/users/restore/${id}`, {}, { preserveScroll: true });
}
function bulkRestore() {
    router.post(
        "/admin/users/trashed/bulk-restore",
        { ids: selected.value },
        {
            preserveScroll: true,
            onSuccess: () => (selected.value = []),
        },
    );
}

const forceConfirm = ref(false);
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
    if (forceOneId.value !== null) {
        router.delete(`/admin/users/force/${forceOneId.value}`, {
            preserveScroll: true,
            onFinish: () => (forceConfirm.value = false),
        });
    } else {
        router.delete("/admin/users/trashed/bulk-force", {
            data: { ids: selected.value },
            preserveScroll: true,
            onSuccess: () => (selected.value = []),
            onFinish: () => (forceConfirm.value = false),
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
                <Link href="/admin/users" class="page__back">← К списку</Link>
            </div>

            <NDataTable
                :columns="columns"
                :rows="users.data"
                :page-size="0"
                selectable
                v-model:selected="selected"
                :selection-label="(n) => `${n} выбрано`"
                clear-label="Снять выделение"
                select-all-label="Выбрать все"
                select-row-label="Выбрать строку"
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
                    <div class="row-actions row-actions--end">
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
                </template>
                <template #empty>
                    <NEmptyState
                        icon="trash"
                        title="Корзина пуста"
                        description="Удалённые пользователи появятся здесь."
                    />
                </template>
            </NDataTable>

            <div v-if="users.last_page > 1" class="page__pager">
                <NPagination
                    :page="users.current_page"
                    :pages="users.last_page"
                    prev-label="Назад"
                    next-label="Вперёд"
                    aria-label="Навигация по страницам"
                    @update:page="reloadPage"
                />
            </div>
        </div>

        <ConfirmModal
            :open="forceConfirm"
            title="Удалить навсегда"
            :message="
                forceOneId !== null
                    ? 'Безвозвратно удалить пользователя? Действие необратимо.'
                    : `Безвозвратно удалить выбранных пользователей (${selected.length})? Действие необратимо.`
            "
            confirm-label="Удалить навсегда"
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
}
.page__back {
    color: var(--text-2);
    font-weight: 600;
    font-size: 13.5px;
    text-decoration: none;
}
</style>
