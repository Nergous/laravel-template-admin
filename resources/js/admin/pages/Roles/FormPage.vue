<script setup lang="ts">
import { Link, useForm } from "@inertiajs/vue3";
import { NButton, NCard } from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import RoleForm from "@/admin/pages/Roles/Partials/Form.vue";
import { useUnsavedGuard } from "@/admin/composables/useUnsavedGuard";
import { formatDateTime } from "@/lib/format";
import { listUrl } from "@/lib/listUrl";

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
useUnsavedGuard(() => form.isDirty && !form.processing);

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
        <div class="page entity-page" :class="{ 'entity-page--wide': isEdit }">
            <div class="entity-page__bar">
                <Link
                    :href="
                        isEdit
                            ? '/admin/roles/' + role.id
                            : listUrl('/admin/roles')
                    "
                    class="entity-page__back"
                    >← {{ isEdit ? "К роли" : "К списку" }}</Link
                >
            </div>

            <div class="entity-page__layout">
                <div class="entity-page__main">
                    <NCard padding="var(--kpi-pad)" class="entity-page__card">
                        <RoleForm
                            :form="form"
                            :all-permissions="allPermissions"
                            :name-readonly="!!role?.is_system"
                            @submit="submit"
                        />
                    </NCard>

                    <div class="entity-page__actions">
                        <NButton
                            :as="Link"
                            :href="
                                isEdit
                                    ? '/admin/roles/' + role.id
                                    : '/admin/roles'
                            "
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

                <aside v-if="isEdit" class="entity-page__aside">
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
    </AdminLayout>
</template>
