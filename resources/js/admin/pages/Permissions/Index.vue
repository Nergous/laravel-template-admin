<script setup lang="ts">
import { reactive, ref, watch, computed } from "vue";
import type { PropType } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import { NIcon, NButton, NInput, NDrawer, NFormField } from "nergous-ui-vue";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import DrawerFooter from "@/admin/components/DrawerFooter.vue";
import { useConfirm } from "@/admin/composables/useConfirm";
import { can } from "@/lib/can";
import { swatchColor } from "@/lib/swatch";

type MatrixRole = {
    id: number;
    name: string;
    label: string;
    is_system: boolean;
    locked: boolean;
    manageable: boolean;
};
type MatrixPermission = {
    id: number;
    name: string;
    action: string;
    label: string;
    is_system: boolean;
};
type MatrixGroup = {
    resource: string;
    label: string;
    permissions: MatrixPermission[];
};

const props = defineProps({
    roles: { type: Array as PropType<MatrixRole[]>, default: () => [] },
    groups: { type: Array as PropType<MatrixGroup[]>, default: () => [] },
    matrix: {
        type: Object as PropType<Record<number, string[]>>,
        default: () => ({}),
    },
    superadminRole: { type: String, default: "admin" },
    filters: {
        type: Object as PropType<{ search?: string }>,
        default: () => ({}),
    },
});

// Mirror the server matrix for optimistic updates.
const state = reactive<{ grants: Record<number, Set<string>> }>({ grants: {} });
function rebuild() {
    const next: Record<number, Set<string>> = {};
    for (const role of props.roles) {
        next[role.id] = new Set(props.matrix[role.id] ?? []);
    }
    state.grants = next;
}
rebuild();
// Refresh local grants when Inertia replaces the server matrix.
watch(() => props.matrix, rebuild);

const canEdit = computed(() => can("permissions.edit"));
const canRename = computed(() => can("permissions.edit"));
function editable(role: MatrixRole) {
    return canEdit.value && !role.locked && role.manageable;
}
const editableRoles = computed(() => props.roles.filter(editable));
const hasForeignRoles = computed(() =>
    props.roles.some((r) => !r.locked && !r.manageable),
);

function isGranted(roleId: number, name: string) {
    return state.grants[roleId]?.has(name) ?? false;
}

type Snapshot = { roleId: number; name: string; had: boolean }[];

function apply(roleIds: number[], names: string[], granted: boolean): Snapshot {
    const snapshot: Snapshot = [];
    for (const roleId of roleIds) {
        const set = state.grants[roleId];
        if (!set) continue;
        for (const name of names) {
            snapshot.push({ roleId, name, had: set.has(name) });
            if (granted) set.add(name);
            else set.delete(name);
        }
    }
    return snapshot;
}

function restore(snapshot: Snapshot) {
    for (const { roleId, name, had } of snapshot) {
        const set = state.grants[roleId];
        if (!set) continue;
        if (had) set.add(name);
        else set.delete(name);
    }
}

// Validation errors re-render the server matrix; 403/419/network errors do
// not, so any unsuccessful visit restores the touched cells by hand.
type MatrixPayload = Record<
    string,
    number | string | boolean | number[] | string[]
>;

function send(url: string, data: MatrixPayload, snapshot: Snapshot) {
    let ok = false;
    router.patch(url, data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            ok = true;
        },
        onFinish: () => {
            if (!ok) restore(snapshot);
        },
    });
}

function toggle(role: MatrixRole, permission: MatrixPermission) {
    if (!editable(role)) return;
    const granted = !isGranted(role.id, permission.name);
    const snapshot = apply([role.id], [permission.name], granted);
    send(
        "/admin/permissions/matrix",
        { role_id: role.id, permission: permission.name, granted },
        snapshot,
    );
}

function bulk(roleIds: number[], names: string[], granted: boolean) {
    if (!roleIds.length || !names.length) return;
    const snapshot = apply(roleIds, names, granted);
    send(
        "/admin/permissions/matrix/bulk",
        { role_ids: roleIds, permissions: names, granted },
        snapshot,
    );
}

/** Tri-state of a group column for one role: all, some or none granted. */
function groupState(role: MatrixRole, group: MatrixGroup) {
    const granted = group.permissions.filter((p) =>
        isGranted(role.id, p.name),
    ).length;
    if (granted === 0) return "off";
    return granted === group.permissions.length ? "on" : "mixed";
}

function toggleGroup(role: MatrixRole, group: MatrixGroup) {
    if (!editable(role)) return;
    bulk(
        [role.id],
        group.permissions.map((p) => p.name),
        groupState(role, group) !== "on",
    );
}

function rowFull(permission: MatrixPermission) {
    return editableRoles.value.every((r) => isGranted(r.id, permission.name));
}

function toggleRow(permission: MatrixPermission) {
    bulk(
        editableRoles.value.map((r) => r.id),
        [permission.name],
        !rowFull(permission),
    );
}

const search = ref(props.filters.search ?? "");
const visibleGroups = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.groups;
    return props.groups
        .map((g) => ({
            ...g,
            permissions: g.label.toLowerCase().includes(q)
                ? g.permissions
                : g.permissions.filter(
                      (p) =>
                          p.name.toLowerCase().includes(q) ||
                          p.label.toLowerCase().includes(q),
                  ),
        }))
        .filter((g) => g.permissions.length > 0);
});

// Reserve the first grid column for permission names.
const gridCols = computed(
    () => `minmax(220px, 1.6fr) repeat(${props.roles.length}, 1fr)`,
);

const drawerOpen = ref(false);
const editing = ref<MatrixPermission | null>(null);
const form = useForm({ name: "" });

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}
function openRename(permission: MatrixPermission) {
    editing.value = permission;
    form.clearErrors();
    form.name = permission.name;
    drawerOpen.value = true;
}
function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            drawerOpen.value = false;
            form.reset();
        },
    };
    if (editing.value) {
        form.put(`/admin/permissions/${editing.value.id}`, options);
    } else {
        form.post("/admin/permissions", options);
    }
}

const del = useConfirm<MatrixPermission>();
function confirmDelete() {
    if (!del.payload) return;
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
            <div class="intro">
                <span class="intro__ico"><NIcon name="bolt" :size="18" /></span>
                <p class="intro__text">
                    <b>Матрица доступа.</b> Разрешения именуются как
                    <code>ресурс.действие</code> (например
                    <code>users.view</code>). Отмечайте ячейки, чтобы выдать
                    роли доступ; флажок в строке группы переключает всю группу.
                </p>
            </div>

            <div class="toolbar">
                <NInput
                    v-model="search"
                    class="toolbar__search"
                    icon="search"
                    placeholder="Найти разрешение…"
                    aria-label="Поиск разрешений"
                />
                <NButton
                    v-if="can('permissions.create')"
                    variant="primary"
                    icon="plus"
                    class="toolbar__add"
                    @click="openCreate"
                    >Добавить разрешение</NButton
                >
            </div>

            <div class="matrix-card">
                <div class="matrix-scroll">
                    <div
                        class="matrix"
                        role="table"
                        aria-label="Матрица доступа: роли и разрешения"
                        :style="{ '--cols': gridCols }"
                    >
                        <div class="row row--head" role="row">
                            <div class="cell cell--corner" role="columnheader">
                                Разрешение
                            </div>
                            <div
                                v-for="role in roles"
                                :key="role.id"
                                class="cell cell--role"
                                :class="{ 'cell--locked': !editable(role) }"
                                role="columnheader"
                                :title="
                                    role.locked
                                        ? 'Права роли суперадминистратора не редактируются'
                                        : !role.manageable
                                          ? 'Этой ролью может управлять только администратор'
                                          : undefined
                                "
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

                        <template
                            v-for="group in visibleGroups"
                            :key="group.resource"
                        >
                            <div class="row row--group" role="row">
                                <div class="cell cell--group" role="rowheader">
                                    <span class="group-label">{{
                                        group.label
                                    }}</span>
                                    <span class="group-key">{{
                                        group.resource
                                    }}</span>
                                </div>
                                <div
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="cell cell--check"
                                    role="cell"
                                >
                                    <button
                                        v-if="editable(role)"
                                        type="button"
                                        class="cbx"
                                        :class="{
                                            on:
                                                groupState(role, group) ===
                                                'on',
                                            mixed:
                                                groupState(role, group) ===
                                                'mixed',
                                        }"
                                        role="checkbox"
                                        :aria-checked="
                                            groupState(role, group) === 'mixed'
                                                ? 'mixed'
                                                : groupState(role, group) ===
                                                  'on'
                                        "
                                        :aria-label="`${role.label}: вся группа «${group.label}»`"
                                        @click="toggleGroup(role, group)"
                                    >
                                        <NIcon
                                            class="cbx__tick"
                                            :name="
                                                groupState(role, group) ===
                                                'mixed'
                                                    ? 'minus'
                                                    : 'check'
                                            "
                                            :size="13"
                                        />
                                    </button>
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
                                        <span class="perm-sub">
                                            {{ permission.label }}
                                            <span
                                                v-if="permission.is_system"
                                                class="perm-system"
                                                title="Системное разрешение: на него опирается код, удалить или переименовать нельзя"
                                            >
                                                <NIcon name="lock" :size="11" />
                                                системное
                                            </span>
                                        </span>
                                    </div>
                                    <div class="perm-actions">
                                        <NButton
                                            v-if="editableRoles.length > 1"
                                            class="perm-del"
                                            variant="ghost"
                                            size="sm"
                                            :icon="
                                                rowFull(permission)
                                                    ? 'minus'
                                                    : 'check'
                                            "
                                            :aria-label="
                                                rowFull(permission)
                                                    ? 'Снять у всех ролей'
                                                    : 'Выдать всем ролям'
                                            "
                                            :title="
                                                rowFull(permission)
                                                    ? 'Снять у всех ролей'
                                                    : 'Выдать всем ролям'
                                            "
                                            @click="toggleRow(permission)"
                                        />
                                        <NButton
                                            v-if="
                                                canRename &&
                                                !permission.is_system
                                            "
                                            class="perm-del"
                                            variant="ghost"
                                            icon="edit"
                                            size="sm"
                                            aria-label="Переименовать разрешение"
                                            title="Переименовать"
                                            @click="openRename(permission)"
                                        />
                                        <NButton
                                            v-if="
                                                can('permissions.delete') &&
                                                !permission.is_system
                                            "
                                            class="perm-del"
                                            variant="ghost"
                                            tone="danger"
                                            icon="trash"
                                            size="sm"
                                            aria-label="Удалить разрешение"
                                            title="Удалить"
                                            @click="del.ask(permission)"
                                        />
                                    </div>
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
                                            locked: !editable(role),
                                        }"
                                        role="checkbox"
                                        :aria-checked="
                                            isGranted(role.id, permission.name)
                                        "
                                        :aria-label="`${role.label}: ${permission.name}`"
                                        :disabled="!editable(role)"
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

                        <div v-if="visibleGroups.length === 0" class="empty">
                            Ничего не найдено по запросу «{{ search }}»
                        </div>
                    </div>
                </div>
            </div>

            <p class="note">
                Полупрозрачная колонка «{{ superadminRole }}» — роль
                суперадминистратора: её права всегда включены и не
                редактируются.
                <template v-if="hasForeignRoles">
                    Затенены также роли, которыми может управлять только
                    администратор.
                </template>
                <template v-if="!canEdit">
                    У вас доступ только на просмотр матрицы.
                </template>
            </p>
        </div>

        <NDrawer
            v-model="drawerOpen"
            :title="editing ? 'Переименовать разрешение' : 'Новое разрешение'"
            subtitle="ресурс.действие"
            close-label="Закрыть"
        >
            <form class="create-form" @submit.prevent="submit">
                <NFormField
                    label="Имя разрешения"
                    :error="form.errors.name"
                    :hint="
                        editing
                            ? 'Код, проверяющий старое имя, перестанет его находить'
                            : 'Формат: ресурс.действие'
                    "
                    required
                >
                    <NInput
                        v-model="form.name"
                        placeholder="ресурс.действие, напр. reports.view"
                        :error="!!form.errors.name"
                        autofocus
                        @keydown.enter.prevent="submit"
                    />
                </NFormField>
            </form>
            <template #footer="{ close }">
                <DrawerFooter
                    :save-label="editing ? 'Сохранить' : 'Создать'"
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
            :message="`Удалить разрешение «${del.payload?.name}»? Оно снимется со всех ролей.`"
            confirm-label="Удалить"
            @confirm="confirmDelete"
            @cancel="del.close"
            @update:open="del.open = $event"
        />
    </AdminLayout>
</template>

<style scoped>
.toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.toolbar__search {
    flex: 1 1 260px;
    max-width: 360px;
}
.toolbar__add {
    margin-left: auto;
}
.perm-actions {
    display: flex;
    align-items: center;
    gap: 2px;
    flex: none;
}
.perm-system {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--text-3);
    font-size: 11px;
}
.empty {
    padding: 28px 16px;
    text-align: center;
    color: var(--text-3);
    font-size: 13.5px;
}

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

.cell--group {
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
/* Row actions appear on row hover. */
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

/* Cell checkbox (same visual as NCheckbox). */
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
.cbx.mixed {
    border-color: var(--accent);
    background: var(--accent-soft);
    color: var(--accent);
}
.cbx.mixed .cbx__tick {
    transform: scale(1);
}
.cbx.locked {
    opacity: 0.55;
    cursor: not-allowed;
}

.note {
    margin: 0;
    font-size: 12.5px;
    color: var(--text-3);
}

.create-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
</style>
