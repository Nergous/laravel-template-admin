<script setup lang="ts">
import { computed, ref } from "vue";
import type { PropType } from "vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import {
    NActivityRow,
    NAvatar,
    NBadge,
    NButton,
    NCard,
    NEmptyState,
} from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import type { SharedProps } from "@/admin/types";
import { can } from "@/lib/can";
import { listUrl } from "@/lib/listUrl";
import { formatDateTime, formatRelative } from "@/lib/format";
import { swatchColor } from "@/lib/swatch";
import { activityVisual as visual } from "@/admin/activityVisuals";

interface UserActivity {
    id: number;
    action: string;
    actionLabel: string;
    actor: string;
    subject: string;
    subjectType: string;
    changesCount: number;
    createdAt: string | null;
}

const props = defineProps({
    user: { type: Object, required: true },
    // Server rule (RbacGuard::canManageUser): may the current user edit/delete this account.
    canManage: { type: Boolean, default: false },
    isSelf: { type: Boolean, default: false },
    // Server rule (Impersonation::canImpersonate): users.impersonate, another active
    // account the current user may manage, never a superadmin.
    canImpersonate: { type: Boolean, default: false },
    // Latest entries where the user is the author or the subject (activity-log.view only).
    activity: { type: Array as PropType<UserActivity[]>, default: () => [] },
    activityLinks: {
        type: Object as PropType<{ byUser: string; aboutUser: string } | null>,
        default: null,
    },
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

const impersonateOpen = ref(false);
const impersonating = ref(false);

function confirmImpersonate() {
    impersonating.value = true;
    router.post(
        `/admin/users/${props.user.id}/impersonate`,
        {},
        {
            onFinish: () => {
                impersonating.value = false;
                impersonateOpen.value = false;
            },
        },
    );
}

function metaFor(log: UserActivity) {
    return log.changesCount > 0 ? `${log.changesCount} изм.` : "";
}
</script>

<template>
    <AdminLayout :title="user.name" subtitle="Карточка пользователя">
        <div class="page entity-page entity-page--wide">
            <div class="entity-page__bar">
                <Link :href="listUrl('/admin/users')" class="entity-page__back"
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
                        v-if="canImpersonate"
                        variant="secondary"
                        icon="eye"
                        @click="impersonateOpen = true"
                        >Войти как пользователь</NButton
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
                        <p
                            v-if="user.is_active === false"
                            class="blocked-reason"
                        >
                            <b>Причина блокировки:</b>
                            {{ user.blocked_reason || "не указана" }}
                        </p>
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

                    <NCard
                        v-if="activityLinks"
                        padding="var(--kpi-pad)"
                        class="entity-page__card"
                    >
                        <div class="activity__head">
                            <h2 class="entity-page__section-title">
                                Активность
                            </h2>
                            <div class="activity__links">
                                <Link :href="activityLinks.byUser"
                                    >Действия пользователя</Link
                                >
                                <Link :href="activityLinks.aboutUser"
                                    >Изменения учётной записи</Link
                                >
                            </div>
                        </div>
                        <ul v-if="activity.length" class="activity__feed">
                            <li v-for="log in activity" :key="log.id">
                                <NActivityRow
                                    :tone="visual(log.action).tone"
                                    :icon="visual(log.action).icon"
                                    :actor="log.actor"
                                    :verb="log.actionLabel"
                                    :object="log.subject"
                                    :tag="log.subjectType"
                                    :time="formatRelative(log.createdAt)"
                                    :meta="metaFor(log)"
                                />
                            </li>
                        </ul>
                        <NEmptyState
                            v-else
                            icon="activity"
                            title="Событий нет"
                            description="Здесь появятся действия пользователя и изменения его учётной записи."
                        />
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

        <ConfirmModal
            :open="impersonateOpen"
            :loading="impersonating"
            title="Войти как пользователь"
            :message="`Вы начнёте работать от имени «${user.name}» и увидите панель с его правами. Ваши действия будут записаны в журнал с пометкой о вас. Вернуться к своему аккаунту можно в любой момент.`"
            confirm-label="Войти"
            :danger="false"
            @confirm="confirmImpersonate"
            @cancel="impersonateOpen = false"
            @update:open="impersonateOpen = $event"
        />
    </AdminLayout>
</template>

<style scoped>
.blocked-reason {
    margin: var(--sp-3) 0 0;
    padding: 10px 12px;
    border-radius: var(--radius-md);
    background: var(--danger-bg);
    color: var(--text);
    font-size: 13px;
}
.activity__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--sp-3);
    flex-wrap: wrap;
}
.activity__links {
    display: flex;
    gap: var(--sp-3);
    flex-wrap: wrap;
    font-size: 13px;
}
.activity__links a {
    color: var(--accent);
    font-weight: 600;
    text-decoration: none;
}
.activity__links a:hover {
    text-decoration: underline;
}
.activity__feed {
    list-style: none;
    margin: 0;
    padding: 0;
}
</style>
