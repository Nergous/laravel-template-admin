<script setup lang="ts">
import type { PropType } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { NButton, NCard, NActionBar } from "nergous-ui-vue";
import { usePageHeader } from "@/admin/composables/usePageHeader";
import DeleteButton from "@/admin/components/DeleteButton.vue";
import RoleForm from "@/admin/pages/Roles/Partials/Form.vue";
import { useUnsavedGuard } from "@/admin/composables/useUnsavedGuard";
import type { AdminRole, PermissionGroups } from "@/admin/types";
import { can } from "@/lib/can";
import { formatDateTime } from "@/lib/format";
import { cameFrom, listUrl } from "@/lib/listUrl";

const props = defineProps({
    mode: { type: String as PropType<"create" | "edit">, required: true },
    role: { type: Object as PropType<AdminRole | null>, default: null },
    allPermissions: {
        type: Object as PropType<PermissionGroups>,
        required: true,
    },
});

const isEdit = props.mode === "edit";
const form = useForm({
    name: props.role?.name ?? "",
    description: props.role?.description ?? "",
    permissions: [...(props.role?.permission_names ?? [])],
});
useUnsavedGuard(() => form.isDirty && !form.processing);

// Back and Cancel return to where editing started: the role's page when it
// was opened from there, otherwise the list with its filters.
const showUrl = props.role ? "/admin/roles/" + props.role.id : "";
const backToShow = isEdit && cameFrom(showUrl);
const backUrl = backToShow ? showUrl : listUrl("/admin/roles");
// System roles and roles assigned to users cannot be deleted (server rule).
const canDelete =
    isEdit &&
    can("roles.delete") &&
    !!props.role &&
    !props.role.is_system &&
    !props.role.users_count;

function submit() {
    if (form.processing) return;

    if (props.role) form.put("/admin/roles/" + props.role.id);
    else form.post("/admin/roles");
}

usePageHeader(() => ({
    title: props.role ? "Редактировать: " + props.role.name : "Новая роль",
    subtitle: "Набор прав доступа",
    crumbs: [
        { label: "Роли", href: listUrl("/admin/roles") },
        ...(props.role ? [{ label: props.role.name, href: showUrl }] : []),
    ],
}));
</script>

<template>
    <div class="page entity-page" :class="{ 'entity-page--wide': isEdit }">
        <div class="entity-page__layout">
            <div class="entity-page__main">
                <NCard padding="var(--kpi-pad)" class="entity-page__card">
                    <RoleForm
                        v-model:name="form.name"
                        v-model:description="form.description"
                        v-model:permissions="form.permissions"
                        :errors="form.errors"
                        :all-permissions="allPermissions"
                        :name-readonly="!!role?.is_system"
                        @submit="submit"
                    />
                </NCard>

                <NActionBar :dirty="form.isDirty">
                    <DeleteButton
                        v-if="canDelete && role"
                        :url="'/admin/roles/' + role.id"
                        :message="`Удалить роль «${role.name}»?`"
                    />
                    <NButton :as="Link" :href="backUrl" variant="secondary"
                        >Отмена</NButton
                    >
                    <NButton
                        variant="primary"
                        icon="check"
                        :loading="form.processing"
                        data-enter-submit
                        @click="submit"
                        >{{
                            isEdit ? "Сохранить изменения" : "Создать роль"
                        }}</NButton
                    >
                </NActionBar>
            </div>

            <aside v-if="isEdit && role" class="entity-page__aside">
                <NCard padding="var(--kpi-pad)" class="entity-page__card">
                    <h2 class="entity-page__section-title">Сведения</h2>
                    <dl class="entity-page__details">
                        <div>
                            <dt>ID</dt>
                            <dd>#{{ role.id }}</dd>
                        </div>
                        <div>
                            <dt>Создана</dt>
                            <dd>{{ formatDateTime(role.created_at) }}</dd>
                        </div>
                        <div>
                            <dt>Обновлена</dt>
                            <dd>{{ formatDateTime(role.updated_at) }}</dd>
                        </div>
                        <div>
                            <dt>Создал</dt>
                            <dd>{{ role.creator_name ?? "—" }}</dd>
                        </div>
                        <div>
                            <dt>Изменил</dt>
                            <dd>{{ role.editor_name ?? "—" }}</dd>
                        </div>
                    </dl>
                </NCard>
            </aside>
        </div>
    </div>
</template>
