<script setup>
// Permissions/Index — матрица «роль × разрешение».
// Колонки — роли, строки — разрешения, сгруппированные по ресурсу.
// Переключение ячейки шлёт PATCH /admin/permissions/matrix; локальное состояние
// обновляется оптимистично и синхронизируется при перезагрузке пропсов.
import { reactive, ref, watch, computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import { NIcon, NButton, NInput, NDrawer, NFormField } from "@/lib/nergous-cit";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import DrawerFooter from "@/admin/components/DrawerFooter.vue";
import { useConfirm } from "@/admin/composables/useConfirm.js";
import { can } from "@/lib/can.js";
import { swatchColor } from "@/lib/swatch.js";

const props = defineProps({
    // Колонки: [{ id, name, label, is_system, locked }]
    roles: { type: Array, default: () => [] },
    // Строки: [{ resource, label, permissions: [{ id, name, action, label }] }]
    groups: { type: Array, default: () => [] },
    // { roleId: ["users.view", ...] } — какие имена прав есть у роли.
    matrix: { type: Object, default: () => ({}) },
});

// Локальное зеркало матрицы: Set имён разрешений по role.id.
// Оптимистично обновляем при клике; пересобираем при приходе свежих пропсов.
const state = reactive({ grants: {} });
function rebuild() {
    const next = {};
    for (const role of props.roles) {
        next[role.id] = new Set(props.matrix[role.id] ?? []);
    }
    state.grants = next;
}
rebuild();
// Inertia заменяет props.matrix новым объектом на каждом ответе, поэтому хватает
// поверхностного watch по ссылке — глубокий обход не нужен. Оптимистичные правки
// мутируют локальные Set'ы (state.grants), а не props, и сюда не попадают.
watch(() => props.matrix, rebuild);

function isGranted(roleId, name) {
    return state.grants[roleId]?.has(name) ?? false;
}

function toggle(role, permission) {
    if (role.locked) return; // системная роль admin — не редактируется
    const set = state.grants[role.id];
    const granted = !set.has(permission.name);
    // оптимистично
    if (granted) set.add(permission.name);
    else set.delete(permission.name);
    router.patch(
        "/admin/permissions/matrix",
        { role_id: role.id, permission: permission.name, granted },
        {
            preserveScroll: true,
            preserveState: true,
            // при ошибке сервера пропсы не меняются — откатываем вручную
            onError: () => {
                if (granted) set.delete(permission.name);
                else set.add(permission.name);
            },
        },
    );
}

// grid-template-columns: первая колонка под код права + N равных колонок под роли.
const gridCols = computed(
    () => `minmax(180px, 1.6fr) repeat(${props.roles.length}, 1fr)`,
);

// --- Создание разрешения ---
// Контроллер делает redirect back на index, поэтому матрица перерисуется
// со свежей строкой после успешного submit.
const createOpen = ref(false);
const form = useForm({ name: "" });

function openCreate() {
    form.reset();
    form.clearErrors();
    createOpen.value = true;
}
function submitCreate() {
    form.post("/admin/permissions", {
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false;
            form.reset();
        },
    });
}

// --- Удаление разрешения ---
const del = useConfirm();
function confirmDelete() {
    del.loading = true;
    router.delete(`/admin/permissions/${del.payload.id}`, {
        preserveScroll: true,
        onFinish: () => del.close(),
    });
}
</script>

<template>
    <AdminLayout
        title="Разрешения"
        subtitle="Матрица доступа · ресурс.действие"
    >
        <div class="page">
            <!-- Вводный баннер -->
            <div class="intro">
                <span class="intro__ico"><NIcon name="bolt" :size="18" /></span>
                <p class="intro__text">
                    <b>Матрица доступа.</b> Разрешения именуются как
                    <code>ресурс.действие</code> (например
                    <code>users.view</code>). Отмечайте ячейки, чтобы выдать
                    роли доступ.
                </p>
            </div>

            <!-- Тулбар над матрицей -->
            <div v-if="can('permissions.create')" class="toolbar">
                <NButton
                    variant="primary"
                    icon="plus"
                    class="toolbar__add"
                    @click="openCreate"
                    >Добавить разрешение</NButton
                >
            </div>

            <!-- Карта-матрица -->
            <div class="matrix-card">
                <div class="matrix-scroll">
                    <div
                        class="matrix"
                        role="table"
                        aria-label="Матрица доступа: роли и разрешения"
                        :style="{ '--cols': gridCols }"
                    >
                        <!-- Шапка: «Разрешение» + роли-колонки -->
                        <div class="row row--head" role="row">
                            <div class="cell cell--corner" role="columnheader">
                                Разрешение
                            </div>
                            <div
                                v-for="role in roles"
                                :key="role.id"
                                class="cell cell--role"
                                :class="{ 'cell--locked': role.locked }"
                                role="columnheader"
                            >
                                <span
                                    class="role-swatch"
                                    :style="{
                                        background: swatchColor(role.name),
                                    }"
                                />
                                <span class="role-label">{{ role.label }}</span>
                            </div>
                        </div>

                        <!-- Группы по ресурсу -->
                        <template v-for="group in groups" :key="group.resource">
                            <div class="row row--group" role="row">
                                <div class="cell cell--group" role="rowheader">
                                    <span class="group-label">{{
                                        group.label
                                    }}</span>
                                    <span class="group-key">{{
                                        group.resource
                                    }}</span>
                                </div>
                            </div>

                            <div
                                v-for="permission in group.permissions"
                                :key="permission.id"
                                class="row row--perm"
                                role="row"
                            >
                                <div class="cell cell--perm" role="rowheader">
                                    <div class="perm-text">
                                        <span class="perm-code">{{
                                            permission.name
                                        }}</span>
                                        <span class="perm-sub">{{
                                            permission.label
                                        }}</span>
                                    </div>
                                    <NButton
                                        v-if="can('permissions.delete')"
                                        class="perm-del"
                                        variant="ghost"
                                        tone="danger"
                                        icon="trash"
                                        size="sm"
                                        aria-label="Удалить разрешение"
                                        @click="del.ask(permission)"
                                    />
                                </div>
                                <div
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="cell cell--check"
                                    role="cell"
                                >
                                    <button
                                        type="button"
                                        class="cbx"
                                        :class="{
                                            on: isGranted(
                                                role.id,
                                                permission.name,
                                            ),
                                            locked: role.locked,
                                        }"
                                        role="checkbox"
                                        :aria-checked="
                                            isGranted(role.id, permission.name)
                                        "
                                        :aria-label="`${role.label}: ${permission.name}`"
                                        :disabled="role.locked"
                                        @click="toggle(role, permission)"
                                    >
                                        <NIcon
                                            class="cbx__tick"
                                            name="check"
                                            :size="13"
                                        />
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Примечание под матрицей -->
            <p class="note">
                Полупрозрачная колонка «admin» — системная роль: её права всегда
                включены и не редактируются.
            </p>
        </div>

        <!-- Drawer создания разрешения (единый паттерн с Пользователями) -->
        <NDrawer
            v-model="createOpen"
            title="Новое разрешение"
            subtitle="ресурс.действие"
            close-label="Закрыть"
        >
            <form class="create-form" @submit.prevent="submitCreate">
                <NFormField
                    label="Имя разрешения"
                    :error="form.errors.name"
                    hint="Формат: ресурс.действие"
                    required
                >
                    <NInput
                        v-model="form.name"
                        placeholder="ресурс.действие, напр. reports.view"
                        :error="!!form.errors.name"
                        autofocus
                        @keydown.enter.prevent="submitCreate"
                    />
                </NFormField>
            </form>
            <template #footer="{ close }">
                <DrawerFooter
                    save-label="Создать"
                    :loading="form.processing"
                    @cancel="
                        () => {
                            form.reset();
                            close();
                        }
                    "
                    @save="submitCreate"
                />
            </template>
        </NDrawer>

        <ConfirmModal
            :open="del.open"
            :loading="del.loading"
            :message="`Удалить разрешение «${del.payload?.name}»? Оно снимется со всех ролей.`"
            confirm-label="Удалить"
            @confirm="confirmDelete"
            @cancel="del.close"
            @update:open="del.open = $event"
        />
    </AdminLayout>
</template>

<style scoped>
/* .page — общая утилита в resources/js/admin/styles.css */

/* --- Тулбар над матрицей --- */
.toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.toolbar__add {
    margin-left: auto;
}

/* --- Вводный баннер --- */
.intro {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 16px;
    border-radius: var(--radius-lg);
    background: var(--accent-soft);
    border: 1px solid var(--border);
}
.intro__ico {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: var(--accent);
    color: #fff;
    flex: none;
}
.intro__text {
    margin: 0;
    font-size: 13.5px;
    line-height: 1.5;
    color: var(--text-2);
}
.intro__text b {
    color: var(--text);
    font-weight: 800;
}
.intro__text code {
    font-family: var(--font-mono);
    font-size: 12.5px;
    color: var(--accent);
}

/* --- Карта-матрица --- */
.matrix-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.matrix-scroll {
    overflow-x: auto;
}
.matrix {
    min-width: max-content;
}

.row {
    display: grid;
    grid-template-columns: var(--cols);
    align-items: center;
}
.row--head {
    height: 50px;
    padding: 0 16px;
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
}
.row--group {
    padding: var(--row-pad, 14px) 16px;
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
    transition: padding 0.18s ease;
}
.row--perm {
    padding: var(--row-pad, 14px) 16px;
    border-bottom: 1px solid var(--border);
    transition: padding 0.18s ease;
}
.row--perm:last-child {
    border-bottom: 0;
}

.cell {
    min-width: 0;
}
.cell--corner {
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--text-3);
}
.cell--role {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text);
}
.cell--role.cell--locked {
    opacity: 0.55;
}
.role-swatch {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    flex: none;
}
.role-label {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* группа занимает всю ширину строки */
.cell--group {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    gap: 8px;
}
.group-label {
    font-size: var(--fs, 14px);
    font-weight: 800;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--text-2);
    transition: font-size 0.18s ease;
}
.group-key {
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--text-3);
}

.cell--perm {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-width: 0;
}
.perm-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
/* Кнопка удаления — появляется при наведении на строку. */
.perm-del {
    flex: none;
    opacity: 0;
    transition: opacity 0.14s ease;
}
.row--perm:hover .perm-del,
.row--perm:focus-within .perm-del {
    opacity: 1;
}
@media (hover: none) {
    .perm-del {
        opacity: 1;
    }
}
.perm-code {
    font-family: var(--font-mono);
    font-size: var(--fs, 14px);
    font-weight: 600;
    color: var(--accent);
    transition: font-size 0.18s ease;
}
.perm-sub {
    font-size: calc(var(--fs, 14px) - 1.5px);
    color: var(--text-3);
    transition: font-size 0.18s ease;
}

.cell--check {
    display: flex;
    justify-content: center;
}

/* --- Чекбокс ячейки (тот же визуал, что NCheckbox) --- */
.cbx {
    position: relative;
    width: 20px;
    height: 20px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1.6px solid var(--border-2);
    background: var(--surface);
    color: #fff;
    padding: 0;
    cursor: pointer;
    outline: none;
    transition:
        background-color 0.15s,
        border-color 0.15s;
}
/* 24×24 hit area (WCAG 2.5.8) over the 20px visual, matching NCheckbox. */
.cbx::before {
    content: "";
    position: absolute;
    inset: -2px;
}
.cbx.on {
    border-color: var(--accent);
    background: var(--accent);
}
.cbx:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.cbx__tick {
    transform: scale(0);
    transition: transform 0.2s cubic-bezier(0.5, 1.6, 0.5, 1);
}
.cbx.on .cbx__tick {
    transform: scale(1);
}
.cbx.locked {
    opacity: 0.55;
    cursor: not-allowed;
}

/* --- Примечание --- */
.note {
    margin: 0;
    font-size: 12.5px;
    color: var(--text-3);
}

/* --- Drawer создания разрешения --- */
.create-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
</style>
