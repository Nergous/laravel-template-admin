<script setup lang="ts">
import { Link, useForm } from "@inertiajs/vue3";
import { NButton, NCard } from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import RoleForm from "@/admin/pages/Roles/Partials/Form.vue";

const props = defineProps({
    mode: { type: String, required: true },
    role: { type: Object, default: null },
    allPermissions: { type: Object, required: true },
});

const isEdit = props.mode === "edit";
const form = useForm({
    name: props.role?.name ?? "",
    description: props.role?.description ?? "",
    permissions: [...(props.role?.permission_names ?? [])],
});
const meta = isEdit
    ? {
          created_by: props.role.creator_name,
          updated_by: props.role.editor_name,
          created_at: props.role.created_at,
          updated_at: props.role.updated_at,
      }
    : null;

function submit() {
    if (form.processing) return;

    if (isEdit) form.put("/admin/roles/" + props.role.id);
    else form.post("/admin/roles");
}
</script>

<template>
    <AdminLayout
        :title="isEdit ? 'Редактировать: ' + role.name : 'Новая роль'"
        subtitle="Набор прав доступа"
    >
        <div class="page entity-page">
            <div class="entity-page__bar">
                <Link
                    :href="isEdit ? '/admin/roles/' + role.id : '/admin/roles'"
                    class="entity-page__back"
                    >← {{ isEdit ? "К роли" : "К списку" }}</Link
                >
            </div>

            <NCard padding="var(--kpi-pad)" class="entity-page__card">
                <RoleForm
                    :form="form"
                    :all-permissions="allPermissions"
                    :meta="meta ?? undefined"
                    :name-readonly="!!role?.is_system"
                    @submit="submit"
                />
            </NCard>

            <div class="entity-page__actions">
                <NButton
                    :as="Link"
                    :href="isEdit ? '/admin/roles/' + role.id : '/admin/roles'"
                    variant="secondary"
                    >Отмена</NButton
                >
                <NButton
                    variant="primary"
                    icon="check"
                    :loading="form.processing"
                    @click="submit"
                    >{{
                        isEdit ? "Сохранить изменения" : "Создать роль"
                    }}</NButton
                >
            </div>
        </div>
    </AdminLayout>
</template>
