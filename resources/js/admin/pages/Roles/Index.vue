<script setup>
import { ref, computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NCard,
    NBadge,
    NButton,
    NInput,
    NPagination,
    NEmptyState,
    NDrawer,
} from "@/lib/nergous-cit";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import DrawerFooter from "@/admin/components/DrawerFooter.vue";
import RoleForm from "@/admin/pages/Roles/Partials/Form.vue";
import { useConfirm } from "@/admin/composables/useConfirm.js";
import { useIndexFilters } from "@/admin/composables/useIndexFilters.js";
import { can } from "@/lib/can.js";
import { formatNumber } from "@/lib/format.js";
import { swatchColor } from "@/lib/swatch.js";

const props = defineProps({
    roles: { type: Object, required: true },
    permissionsTotal: { type: Number, default: 0 },
    // Grouped permissions for the matrix in the drawer: { users:[{id,name}], ... }
    allPermissions: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search ?? "");
const { reload, onSearch } = useIndexFilters("/admin/roles", () => ({
    search: search.value,
}));

/* ---------- drawer (create | edit) ---------- */
const drawerOpen = ref(false);
const mode = ref("create"); // create | edit
const editing = ref(null); // full role row when editing
const form = useForm({ name: "", description: "", permissions: [] });

const drawerTitle = computed(() =>
    mode.value === "edit" && editing.value ? editing.value.name : "Новая роль",
);
const drawerSubtitle = computed(() =>
    mode.value === "edit" && editing.value
        ? editing.value.description || "Роль"
        : "Набор прав доступа",
);
// The "Details" panel in the form — only on edit.
const drawerMeta = computed(() =>
    mode.value === "edit" && editing.value
        ? {
              created_by: editing.value.creator_name,
              updated_by: editing.value.editor_name,
              created_at: editing.value.created_at,
              updated_at: editing.value.updated_at,
          }
        : null,
);

function openCreate() {
    mode.value = "create";
    editing.value = null;
    form.clearErrors();
    form.defaults({ name: "", description: "", permissions: [] });
    form.reset();
    drawerOpen.value = true;
}
function openEdit(role) {
    mode.value = "edit";
    editing.value = role;
    form.clearErrors();
    form.defaults({
        name: role.name,
        description: role.description ?? "",
        permissions: [...(role.permission_names ?? [])],
    });
    form.reset();
    drawerOpen.value = true;
}
function closeDrawer() {
    drawerOpen.value = false;
}
function submit() {
    if (mode.value === "edit" && editing.value) {
        form.put(`/admin/roles/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess: closeDrawer,
        });
    } else {
        form.post("/admin/roles", {
            preserveScroll: true,
            onSuccess: closeDrawer,
        });
    }
}

/* ---------- delete ---------- */
const del = useConfirm();

function confirmDelete() {
    del.loading = true;
    router.delete(`/admin/roles/${del.payload.id}`, {
        preserveScroll: true,
        onFinish: () => del.close(),
    });
}
</script>

<template>
    <AdminLayout title="Роли" subtitle="Наборы прав доступа">
        <div class="page">
            <div class="page__toolbar">
                <div class="page__search">
                    <NInput
                        v-model="search"
                        icon="search"
                        placeholder="Поиск по названию"
                        @update:model-value="onSearch"
                    />
                </div>
                <NButton
                    v-if="can('roles.create')"
                    variant="primary"
                    icon="plus"
                    @click="openCreate"
                    >Создать</NButton
                >
            </div>

            <NEmptyState
                v-if="!roles.data.length"
                icon="shield"
                title="Роли не найдены"
                :description="
                    search
                        ? 'Попробуйте изменить запрос поиска.'
                        : 'Создайте первую роль, чтобы управлять доступом.'
                "
            >
                <NButton
                    v-if="can('roles.create')"
                    variant="primary"
                    icon="plus"
                    @click="openCreate"
                    >Создать роль</NButton
                >
            </NEmptyState>

            <template v-else>
                <div class="roles">
                    <NCard
                        v-for="role in roles.data"
                        :key="role.id"
                        hover
                        padding="var(--kpi-pad)"
                        class="roles__card"
                    >
                        <div class="role">
                            <div class="role__head">
                                <span
                                    class="role__swatch"
                                    :style="{
                                        background: swatchColor(role.name),
                                    }"
                                />
                                <h2 class="role__name">
                                    <button
                                        v-if="can('roles.edit')"
                                        type="button"
                                        class="role__name-link"
                                        @click="openEdit(role)"
                                    >
                                        {{ role.name }}
                                    </button>
                                    <span v-else class="role__name-link">{{
                                        role.name
                                    }}</span>
                                </h2>
                                <NBadge size="sm">{{
                                    role.is_system ? "системная" : "кастомная"
                                }}</NBadge>
                                <div class="role__actions">
                                    <NButton
                                        v-if="
                                            can('roles.delete') &&
                                            !role.is_system
                                        "
                                        variant="ghost"
                                        tone="danger"
                                        icon="trash"
                                        size="sm"
                                        aria-label="Удалить роль"
                                        @click="del.ask(role)"
                                    />
                                </div>
                            </div>

                            <div class="role__desc">
                                {{ role.description || "Описание не задано" }}
                            </div>

                            <div class="role__footer">
                                <div class="role__stat">
                                    <b class="role__num">{{
                                        formatNumber(role.users_count)
                                    }}</b>
                                    <span class="role__label"
                                        >пользователей</span
                                    >
                                </div>
                                <div class="role__stat">
                                    <b class="role__num"
                                        >{{ role.permissions_count }} /
                                        {{ permissionsTotal }}</b
                                    >
                                    <span class="role__label">разрешений</span>
                                </div>
                            </div>
                        </div>
                    </NCard>
                </div>

                <p class="roles__hint">
                    Нажмите на роль, чтобы изменить её разрешения.
                </p>

                <div v-if="roles.last_page > 1" class="page__pager">
                    <NPagination
                        :page="roles.current_page"
                        :pages="roles.last_page"
                        prev-label="Назад"
                        next-label="Вперёд"
                        aria-label="Навигация по страницам"
                        @update:page="(p) => reload({ page: p })"
                    />
                </div>
            </template>
        </div>

        <!-- create | edit drawer -->
        <NDrawer
            v-model="drawerOpen"
            :title="drawerTitle"
            :subtitle="drawerSubtitle"
            close-label="Закрыть"
        >
            <RoleForm
                :form="form"
                :all-permissions="allPermissions"
                :meta="drawerMeta"
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
            :message="`Удалить роль «${del.payload?.name}»? Пользователи потеряют связанные права.`"
            @confirm="confirmDelete"
            @cancel="del.close"
            @update:open="del.open = $event"
        />
    </AdminLayout>
</template>

<style scoped>
/* .page / .page__pager — shared utilities in resources/js/admin/styles.css */
.page__toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
}
.page__search {
    flex: 1;
    max-width: 360px;
}

.roles {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--kpi-gap);
}
.roles__card {
    position: relative;
}
.roles__hint {
    margin: 0;
    font-size: 13px;
    color: var(--text-3);
}

.role {
    display: flex;
    flex-direction: column;
}
.role__head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
}
.role__swatch {
    width: 11px;
    height: 11px;
    border-radius: 4px;
    flex: none;
}
.role__name {
    flex: 1;
    min-width: 0;
    margin: 0;
    font-size: calc(var(--fs) + 1.5px);
    font-weight: 800;
    letter-spacing: -0.01em;
    color: var(--text);
}
.role__name-link {
    display: block;
    width: 100%;
    color: inherit;
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
button.role__name-link {
    margin: 0;
    padding: 0;
    border: 0;
    background: none;
    font: inherit;
    text-align: left;
    cursor: pointer;
}
/* Stretched button: the whole card is clickable, but there's a single button in the DOM. */
button.role__name-link::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: var(--radius-lg);
}
button.role__name-link:focus-visible {
    outline: none;
}
button.role__name-link:focus-visible::after {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.role__actions {
    display: flex;
    align-items: center;
    gap: 2px;
    flex: none;
    /* Above the stretched link, otherwise the overlay would intercept the "Delete" click. */
    position: relative;
    z-index: 1;
    opacity: 0;
    transition: opacity 0.14s ease;
}
.roles__card:hover .role__actions,
.roles__card:focus-within .role__actions {
    opacity: 1;
}
.role__desc {
    font-size: calc(var(--fs) - 1px);
    line-height: 1.45;
    color: var(--text-3);
    min-height: 38px;
}
.role__footer {
    display: flex;
    gap: 20px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}
.role__stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.role__num {
    font-size: calc(var(--fs) + 4px);
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--text);
}
.role__label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-3);
}

/* Touch screens have no hover — show the actions permanently. */
@media (hover: none) {
    .role__actions {
        opacity: 1;
    }
}
</style>
