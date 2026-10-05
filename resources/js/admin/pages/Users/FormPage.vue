<script setup lang="ts">
import type { PropType } from "vue";
import type { AdminRole, AdminUser } from "@/admin/types";
import { Link, useForm } from "@inertiajs/vue3";
import { NButton, NCard, NActionBar } from "nergous-ui-vue";
import { usePageHeader } from "@/admin/composables/usePageHeader";
import DeleteButton from "@/admin/components/DeleteButton.vue";
import UserForm from "@/admin/pages/Users/Partials/Form.vue";
import { useUnsavedGuard } from "@/admin/composables/useUnsavedGuard";
import { can } from "@/lib/can";
import { formatDateTime } from "@/lib/format";
import { cameFrom, listUrl } from "@/lib/listUrl";

const props = defineProps({
    mode: { type: String as PropType<"create" | "edit">, required: true },
    user: { type: Object as PropType<AdminUser | null>, default: null },
    allRoles: { type: Array as PropType<AdminRole[]>, required: true },
    isSelf: { type: Boolean, default: false },
});

const isEdit = props.mode === "edit";
const form = useForm({
    name: props.user?.name ?? "",
    email: props.user?.email ?? "",
    password: "",
    roles: props.user?.roles?.map((role) => role.name) ?? [],
    is_active: props.user?.is_active ?? true,
    blocked_reason: props.user?.blocked_reason ?? "",
    must_change_password: props.user?.must_change_password ?? false,
});

useUnsavedGuard(() => form.isDirty && !form.processing);

// Back and Cancel return to where editing started: the user's page when it
// was opened from there, otherwise the list with its filters.
const showUrl = props.user ? `/admin/users/${props.user.id}` : "";
const backToShow = isEdit && cameFrom(showUrl);
const backUrl = backToShow ? showUrl : listUrl("/admin/users");
// The edit page opens only for manageable accounts; the own one cannot be deleted.
const canDelete = isEdit && can("users.delete") && !props.isSelf;

function submit() {
    if (form.processing) return;

    if (props.user) form.put(`/admin/users/${props.user.id}`);
    else form.post("/admin/users");
}

usePageHeader(() => ({
    title: props.user
        ? `Редактировать: ${props.user.name}`
        : "Новый пользователь",
    subtitle: props.user ? props.user.email : "Учётная запись и роли",
    crumbs: [
        { label: "Пользователи", href: listUrl("/admin/users") },
        ...(props.user ? [{ label: props.user.name, href: showUrl }] : []),
    ],
}));
</script>

<template>
    <div class="page entity-page" :class="{ 'entity-page--wide': isEdit }">
        <div class="entity-page__layout">
            <div class="entity-page__main">
                <NCard padding="var(--kpi-pad)" class="entity-page__card">
                    <UserForm
                        v-model:name="form.name"
                        v-model:email="form.email"
                        v-model:password="form.password"
                        v-model:roles="form.roles"
                        v-model:is-active="form.is_active"
                        v-model:blocked-reason="form.blocked_reason"
                        v-model:must-change-password="form.must_change_password"
                        :errors="form.errors"
                        :all-roles="allRoles"
                        :is-edit="isEdit"
                        :user="user"
                        :is-self="isSelf"
                        @submit="submit"
                    />
                </NCard>

                <NActionBar :dirty="form.isDirty">
                    <DeleteButton
                        v-if="canDelete && user"
                        :url="`/admin/users/${user.id}`"
                        :message="`Отправить пользователя «${user.name}» в корзину?`"
                        confirm-label="В корзину"
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
                            isEdit
                                ? "Сохранить изменения"
                                : "Создать пользователя"
                        }}</NButton
                    >
                </NActionBar>
            </div>

            <aside v-if="isEdit && user" class="entity-page__aside">
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
</template>
