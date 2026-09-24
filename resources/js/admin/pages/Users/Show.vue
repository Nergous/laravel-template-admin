<script setup lang="ts">
import { computed, ref } from "vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import { NAvatar, NBadge, NButton, NCard } from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import type { SharedProps } from "@/admin/types";
import { can } from "@/lib/can";
import { formatDateTime } from "@/lib/format";
import { swatchColor } from "@/lib/swatch";

const props = defineProps({
    user: { type: Object, required: true },
    // Server rule (RbacGuard::canManageUser): may the current user edit/delete this account.
    canManage: { type: Boolean, default: false },
    isSelf: { type: Boolean, default: false },
});

const page = usePage<SharedProps>();
// The server rejects self-deletion, so the button is hidden on the own profile.
const canDelete = computed(
    () =>
        can("users.delete") &&
        props.canManage &&
        page.props.auth.user?.id !== props.user.id,
);
const deleteOpen = ref(false);
const deleting = ref(false);

function confirmDelete() {
    deleting.value = true;
    router.delete(`/admin/users/${props.user.id}`, {
        onFinish: () => {
            deleting.value = false;
            deleteOpen.value = false;
        },
    });
}

const copyState = ref("");
async function copyLink() {
    try {
        await navigator.clipboard.writeText(window.location.href);
        copyState.value = "Ссылка скопирована";
    } catch {
        copyState.value = "Не удалось скопировать";
    }
}
</script>

<template>
    <AdminLayout :title="user.name" subtitle="Карточка пользователя">
        <div class="page entity-page entity-page--wide">
            <div class="entity-page__bar">
                <Link href="/admin/users" class="entity-page__back"
                    >← К списку пользователей</Link
                >
                <div class="entity-page__actions">
                    <NButton
                        variant="secondary"
                        icon="copy"
                        @click="copyLink"
                        >{{ copyState || "Скопировать ссылку" }}</NButton
                    >
                    <NButton
                        v-if="canDelete"
                        variant="danger"
                        icon="trash"
                        @click="deleteOpen = true"
                        >Удалить</NButton
                    >
                    <NButton
                        v-if="can('users.edit') && canManage"
                        :as="Link"
                        :href="`/admin/users/${user.id}/edit`"
                        variant="primary"
                        icon="edit"
                        >Редактировать</NButton
                    >
                </div>
            </div>

            <div class="entity-page__layout">
                <div class="entity-page__main">
                    <NCard padding="var(--kpi-pad)" class="entity-page__card">
                        <div class="entity-page__profile">
                            <NAvatar :name="user.name" :size="52" />
                            <div>
                                <h2>{{ user.name }}</h2>
                                <p>{{ user.email }}</p>
                            </div>
                            <NBadge
                                v-if="user.is_active === false"
                                tone="danger"
                                pill
                                >Заблокирован</NBadge
                            >
                            <NBadge
                                v-if="user.must_change_password"
                                tone="warn"
                                pill
                                >Должен сменить пароль</NBadge
                            >
                        </div>
                    </NCard>

                    <NCard padding="var(--kpi-pad)" class="entity-page__card">
                        <h2 class="entity-page__section-title">Доступ</h2>
                        <div class="entity-page__badges">
                            <template v-if="user.roles?.length">
                                <template
                                    v-for="role in user.roles"
                                    :key="role.id"
                                >
                                    <Link
                                        v-if="can('roles.view')"
                                        :href="'/admin/roles/' + role.id"
                                        class="entity-page__badge-link"
                                    >
                                        <NBadge
                                            tone="neutral"
                                            pill
                                            :swatch="swatchColor(role.name)"
                                            >{{ role.name }}</NBadge
                                        >
                                    </Link>
                                    <NBadge
                                        v-else
                                        tone="neutral"
                                        pill
                                        :swatch="swatchColor(role.name)"
                                        >{{ role.name }}</NBadge
                                    >
                                </template>
                            </template>
                            <span v-else>Роли не назначены</span>
                        </div>
                    </NCard>
                </div>

                <aside class="entity-page__aside">
                    <NCard padding="var(--kpi-pad)" class="entity-page__card">
                        <h2 class="entity-page__section-title">Сведения</h2>
                        <dl class="entity-page__details">
                            <div>
                                <dt>ID</dt>
                                <dd>#{{ user.id }}</dd>
                            </div>
                            <div>
                                <dt>Email</dt>
                                <dd>{{ user.email }}</dd>
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

        <ConfirmModal
            :open="deleteOpen"
            :loading="deleting"
            :message="`Отправить пользователя «${user.name}» в корзину?`"
            confirm-label="В корзину"
            @confirm="confirmDelete"
            @cancel="deleteOpen = false"
            @update:open="deleteOpen = $event"
        />
    </AdminLayout>
</template>
