<script setup lang="ts">
import type { PropType } from "vue";
import type { AdminRole } from "@/admin/types";
import { Link, useForm } from "@inertiajs/vue3";
import { NButton, NCard } from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import UserForm from "@/admin/pages/Users/Partials/Form.vue";

const props = defineProps({
    mode: { type: String, required: true },
    user: { type: Object, default: null },
    allRoles: { type: Array as PropType<AdminRole[]>, required: true },
});

const isEdit = props.mode === "edit";
const form = useForm({
    name: props.user?.name ?? "",
    email: props.user?.email ?? "",
    password: "",
    roles: props.user?.roles?.map((role: { name: string }) => role.name) ?? [],
});

function submit() {
    if (form.processing) return;

    if (isEdit) form.put(`/admin/users/${props.user.id}`);
    else form.post("/admin/users");
}
</script>

<template>
    <AdminLayout
        :title="isEdit ? `Редактировать: ${user.name}` : 'Новый пользователь'"
        :subtitle="isEdit ? user.email : 'Учётная запись и роли'"
    >
        <div class="page entity-page">
            <div class="entity-page__bar">
                <Link
                    :href="isEdit ? `/admin/users/${user.id}` : '/admin/users'"
                    class="entity-page__back"
                    >← {{ isEdit ? "К пользователю" : "К списку" }}</Link
                >
            </div>

            <NCard padding="var(--kpi-pad)" class="entity-page__card">
                <UserForm
                    :form="form"
                    :all-roles="allRoles"
                    :is-edit="isEdit"
                    :user="user"
                    @submit="submit"
                />
            </NCard>

            <div class="entity-page__actions">
                <NButton
                    :as="Link"
                    :href="isEdit ? `/admin/users/${user.id}` : '/admin/users'"
                    variant="secondary"
                    >Отмена</NButton
                >
                <NButton
                    variant="primary"
                    icon="check"
                    :loading="form.processing"
                    @click="submit"
                    >{{
                        isEdit ? "Сохранить изменения" : "Создать пользователя"
                    }}</NButton
                >
            </div>
        </div>
    </AdminLayout>
</template>
