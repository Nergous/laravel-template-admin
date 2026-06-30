<script setup>
import { ref, computed } from "vue";
import { Link, router, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NDataTable,
    NPagination,
    NButton,
    NInput,
    NSelect,
    NBadge,
    NAvatar,
    NDrawer,
    NEmptyState,
} from "@/lib/nergous-cit";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import DrawerFooter from "@/admin/components/DrawerFooter.vue";
import UserForm from "@/admin/pages/Users/Partials/Form.vue";
import { useConfirm } from "@/admin/composables/useConfirm.js";
import { useIndexFilters } from "@/admin/composables/useIndexFilters.js";
import { can } from "@/lib/can.js";
import { formatDateShort } from "@/lib/format.js";
import { swatchColor } from "@/lib/swatch.js";

const props = defineProps({
    users: { type: Object, required: true },
    roles: { type: Object, default: () => ({}) }, // { admin:'admin', ... }
    allRoles: { type: Array, default: () => [] }, // [{ name, description }]
    trashedCount: { type: Number, default: 0 },
    currentSort: { type: String, default: "id" },
    currentDirection: { type: String, default: "desc" },
    filters: { type: Object, default: () => ({}) },
});

/* ---------- toolbar / server filters ---------- */
const search = ref(props.filters.search ?? "");
const role = ref(props.filters.role ?? "");

// NSelect — варианты через :options, НЕ слотом <option>.
const roleOptions = computed(() => [
    { value: "", label: "Все роли" },
    ...Object.entries(props.roles).map(([value, label]) => ({ value, label })),
]);

const { reload, onSearch, onSort } = useIndexFilters("/admin/users", () => ({
    search: search.value,
    role: role.value,
    sort: props.currentSort,
    direction: props.currentDirection,
}));

/* ---------- rows ---------- */
const rows = computed(() => props.users.data);

/* ---------- table columns ---------- */
const columns = [
    { key: "name", label: "Пользователь", sortable: true },
    { key: "email", label: "Email" },
    { key: "roles", label: "Роли" },
    { key: "created_at", label: "Добавлен", sortable: true, width: "140px" },
    { key: "actions", label: "Действия", width: "80px", align: "center" },
];

/* ---------- drawer (create | edit) ---------- */
const drawerOpen = ref(false);
const mode = ref("create"); // create | edit
const editing = ref(null); // полная строка при edit
const form = useForm({ name: "", email: "", password: "", roles: [] });

const drawerTitle = computed(() =>
    mode.value === "edit" && editing.value
        ? editing.value.name
        : "Новый пользователь",
);
const drawerSubtitle = computed(() =>
    mode.value === "edit" && editing.value
        ? editing.value.email
        : "Заполните данные и отправьте приглашение",
);

function openCreate() {
    mode.value = "create";
    editing.value = null;
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}
function openEdit(row) {
    mode.value = "edit";
    editing.value = row;
    form.clearErrors();
    form.defaults({
        name: row.name,
        email: row.email,
        password: "",
        roles: row.roles.map((r) => r.name),
    });
    form.reset();
    drawerOpen.value = true;
}
function closeDrawer() {
    drawerOpen.value = false;
}
function submit() {
    if (mode.value === "edit" && editing.value) {
        form.put(`/admin/users/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess: closeDrawer,
        });
    } else {
        form.post("/admin/users", {
            preserveScroll: true,
            onSuccess: closeDrawer,
        });
    }
}

/* ---------- delete ---------- */
const del = useConfirm();

function confirmDelete() {
    del.loading = true;
    router.delete(`/admin/users/${del.payload.id}`, {
        preserveScroll: true,
        onFinish: () => del.close(),
    });
}
</script>

<template>
    <AdminLayout
        title="Пользователи"
        :subtitle="`${users.total} учётных записей`"
    >
        <div class="page">
            <!-- inline toolbar -->
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
                    v-if="can('users.create')"
                    variant="primary"
                    icon="plus"
                    class="toolbar__add"
                    @click="openCreate"
                    >Добавить</NButton
                >
            </div>

            <NDataTable
                :columns="columns"
                :rows="rows"
                :page-size="0"
                :hover="false"
                manual-sort
                :sort-key="currentSort"
                :sort-dir="currentDirection"
                empty-text="Нет данных"
                @sort-change="onSort"
            >
                <!-- Пользователь -->
                <template #cell-name="{ row }">
                    <div class="ucell">
                        <NAvatar :name="row.name" :size="36" />
                        <div class="ucell__name">{{ row.name }}</div>
                    </div>
                </template>

                <!-- Email -->
                <template #cell-email="{ row }">
                    <span class="email-cell">{{ row.email }}</span>
                </template>

                <!-- Роли -->
                <template #cell-roles="{ row }">
                    <span class="roles-cell">
                        <NBadge
                            v-for="r in row.roles"
                            :key="r.id"
                            tone="neutral"
                            pill
                            :swatch="swatchColor(r.name)"
                            >{{ r.name }}</NBadge
                        >
                        <span v-if="!row.roles?.length" class="muted">—</span>
                    </span>
                </template>

                <!-- Добавлен -->
                <template #cell-created_at="{ row }">
                    <span class="created">{{
                        formatDateShort(row.created_at)
                    }}</span>
                </template>

                <!-- Действия -->
                <template #cell-actions="{ row }">
                    <div class="row-actions row-actions--center">
                        <NButton
                            v-if="can('users.edit')"
                            variant="ghost"
                            tone="accent"
                            icon="edit"
                            size="sm"
                            class="row-actions__btn"
                            aria-label="Редактировать"
                            @click="openEdit(row)"
                        />
                        <NButton
                            v-if="can('users.delete')"
                            variant="ghost"
                            tone="danger"
                            icon="trash"
                            size="sm"
                            class="row-actions__btn"
                            aria-label="Удалить"
                            @click="del.ask(row)"
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

            <div v-if="users.last_page > 1" class="page__pager">
                <NPagination
                    :page="users.current_page"
                    :pages="users.last_page"
                    prev-label="Назад"
                    next-label="Вперёд"
                    aria-label="Навигация по страницам"
                    @update:page="(p) => reload({ page: p })"
                />
            </div>
        </div>

        <!-- create | edit drawer -->
        <NDrawer
            v-model="drawerOpen"
            :title="drawerTitle"
            :subtitle="drawerSubtitle"
            close-label="Закрыть"
        >
            <UserForm
                :form="form"
                :all-roles="allRoles"
                :is-edit="mode === 'edit'"
                :user="editing"
            />
            <template #footer="{ close }">
                <DrawerFooter
                    :loading="form.processing"
                    @cancel="
                        () => {
                            form.reset();
                            close();
                        }
                    "
                    @save="submit"
                />
            </template>
        </NDrawer>

        <ConfirmModal
            :open="del.open"
            :loading="del.loading"
            :message="`Отправить пользователя «${del.payload?.name}» в корзину?`"
            confirm-label="В корзину"
            @confirm="confirmDelete"
            @cancel="del.close"
            @update:open="del.open = $event"
        />
    </AdminLayout>
</template>

<style scoped>
/* .page / .row-actions* — общие утилиты в resources/js/admin/styles.css */

/* inline toolbar */
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

/* user cell */
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
