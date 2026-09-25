<script setup lang="ts">
import type { PropType } from "vue";
import type { AdminRole } from "@/admin/types";
import { Link, useForm } from "@inertiajs/vue3";
import { NButton, NCard } from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import UserForm from "@/admin/pages/Users/Partials/Form.vue";
import { useUnsavedGuard } from "@/admin/composables/useUnsavedGuard";
import { formatDateTime } from "@/lib/format";
import { listUrl } from "@/lib/listUrl";

const props = defineProps({
    mode: { type: String, required: true },
    user: { type: Object, default: null },
    allRoles: { type: Array as PropType<AdminRole[]>, required: true },
    isSelf: { type: Boolean, default: false },
});

const isEdit = props.mode === "edit";
const form = useForm({
    name: props.user?.name ?? "",
    email: props.user?.email ?? "",
    password: "",
    roles: props.user?.roles?.map((role: { name: string }) => role.name) ?? [],
    is_active: props.user?.is_active ?? true,
    blocked_reason: props.user?.blocked_reason ?? "",
    must_change_password: props.user?.must_change_password ?? false,
});

useUnsavedGuard(() => form.isDirty && !form.processing);

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
        <div class="page entity-page" :class="{ 'entity-page--wide': isEdit }">
            <div class="entity-page__bar">
                <Link
                    :href="
                        isEdit
                            ? `/admin/users/${user.id}`
                            : listUrl('/admin/users')
                    "
                    class="entity-page__back"
                    >← {{ isEdit ? "К пользователю" : "К списку" }}</Link
                >
            </div>

            <div class="entity-page__layout">
                <div class="entity-page__main">
                    <NCard padding="var(--kpi-pad)" class="entity-page__card">
                        <UserForm
                            :form="form"
                            :all-roles="allRoles"
                            :is-edit="isEdit"
                            :user="user"
                            :is-self="isSelf"
                            @submit="submit"
                        />
                    </NCard>

                    <div class="entity-page__actions">
                        <NButton
                            :as="Link"
                            :href="
                                isEdit
                                    ? `/admin/users/${user.id}`
                                    : '/admin/users'
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
                                isEdit
                                    ? "Сохранить изменения"
                                    : "Создать пользователя"
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
                                <dd>#{{ user.id }}</dd>
                            </div>
                            <div>
                                <dt>Последний вход</dt>
                                <dd>
                                    {{ formatDateTime(user.last_login_at) }}
                                </dd>
                            </div>
                            <div>
                                <dt>Добавлен</dt>
                                <dd>{{ formatDateTime(user.created_at) }}</dd>
                            </div>
                            <div>
                                <dt>Обновлён</dt>
                                <dd>{{ formatDateTime(user.updated_at) }}</dd>
                            </div>
                            <div>
                                <dt>Создал</dt>
                                <dd>{{ user.creator?.name ?? "—" }}</dd>
                            </div>
                            <div>
                                <dt>Изменил</dt>
                                <dd>{{ user.editor?.name ?? "—" }}</dd>
                            </div>
                        </dl>
                    </NCard>
                </aside>
            </div>
        </div>
    </AdminLayout>
</template>
