<script setup lang="ts">
import { computed, ref } from "vue";
import type { PropType } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import {
    NButton,
    NCard,
    NFormField,
    NInput,
    NModal,
    NBadge,
    NEmptyState,
} from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { useUnsavedGuard } from "@/admin/composables/useUnsavedGuard";
import { formatDateTime, formatRelative } from "@/lib/format";

interface SessionRow {
    key: string;
    ip: string | null;
    agent: string | null;
    last_active_at: string;
    current: boolean;
}

const props = defineProps({
    profile: {
        type: Object as PropType<{
            name: string;
            email: string;
            must_change_password: boolean;
            last_login_at: string | null;
        }>,
        required: true,
    },
    sessions: { type: Array as PropType<SessionRow[]>, default: () => [] },
    sessionsSupported: { type: Boolean, default: false },
});

const profileForm = useForm({
    name: props.profile.name,
    email: props.profile.email,
    current_password: "",
});
// The server asks for the current password only when the email changes.
const emailChanged = computed(
    () => profileForm.email.trim() !== props.profile.email,
);
const passwordForm = useForm({
    current_password: "",
    password: "",
    password_confirmation: "",
});

useUnsavedGuard(
    () =>
        (profileForm.isDirty || passwordForm.isDirty) &&
        !profileForm.processing &&
        !passwordForm.processing,
);

function saveProfile() {
    profileForm
        .transform((data) => ({
            ...data,
            current_password: emailChanged.value ? data.current_password : "",
        }))
        .put("/admin/profile", {
            preserveScroll: true,
            onFinish: () => profileForm.reset("current_password"),
            onSuccess: () => profileForm.defaults(),
        });
}

function savePassword() {
    passwordForm.put("/admin/profile/password", {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => passwordForm.reset("password", "password_confirmation"),
    });
}

// "Sign out other devices" requires the current password.
const othersOpen = ref(false);
const othersForm = useForm({ password: "" });
function openOthers() {
    othersForm.reset();
    othersForm.clearErrors();
    othersOpen.value = true;
}
function logoutOthers() {
    othersForm.post("/admin/profile/sessions/logout-others", {
        preserveScroll: true,
        onSuccess: () => {
            othersOpen.value = false;
            othersForm.reset();
        },
    });
}

const endKey = ref<string | null>(null);
const ending = ref(false);
function endSession() {
    if (!endKey.value) return;
    ending.value = true;
    router.delete(`/admin/profile/sessions/${endKey.value}`, {
        preserveScroll: true,
        onFinish: () => {
            ending.value = false;
            endKey.value = null;
        },
    });
}

// A short, human label for a user agent string.
function deviceLabel(agent: string | null): string {
    if (!agent) return "Неизвестное устройство";
    const browser = /Edg\//.test(agent)
        ? "Edge"
        : /OPR\//.test(agent)
          ? "Opera"
          : /Firefox\//.test(agent)
            ? "Firefox"
            : /Chrome\//.test(agent)
              ? "Chrome"
              : /Safari\//.test(agent)
                ? "Safari"
                : "Браузер";
    const os = /Windows/.test(agent)
        ? "Windows"
        : /Android/.test(agent)
          ? "Android"
          : /iPhone|iPad/.test(agent)
            ? "iOS"
            : /Mac OS/.test(agent)
              ? "macOS"
              : /Linux/.test(agent)
                ? "Linux"
                : "";
    return os ? `${browser} · ${os}` : browser;
}
</script>

<template>
    <AdminLayout title="Мой профиль" subtitle="Учётная запись и безопасность">
        <div class="page profile">
            <div
                v-if="profile.must_change_password"
                class="profile__notice"
                role="alert"
            >
                <b>Нужно сменить пароль.</b> Администратор попросил задать новый
                пароль. Остальные разделы откроются после смены.
            </div>

            <NCard padding="var(--kpi-pad)">
                <h2 class="profile__title">Профиль</h2>
                <form class="profile__form" @submit.prevent="saveProfile">
                    <NFormField
                        label="Имя"
                        :error="profileForm.errors.name"
                        required
                    >
                        <NInput
                            v-model="profileForm.name"
                            autocomplete="name"
                            :error="!!profileForm.errors.name"
                        />
                    </NFormField>
                    <NFormField
                        label="Email"
                        :error="profileForm.errors.email"
                        required
                    >
                        <NInput
                            v-model="profileForm.email"
                            type="email"
                            icon="mail"
                            autocomplete="email"
                            :error="!!profileForm.errors.email"
                        />
                    </NFormField>
                    <NFormField
                        v-if="emailChanged"
                        label="Текущий пароль"
                        hint="Нужен для смены email"
                        :error="profileForm.errors.current_password"
                        required
                    >
                        <NInput
                            v-model="profileForm.current_password"
                            type="password"
                            icon="lock"
                            autocomplete="current-password"
                            reveal-label="Показать пароль"
                            hide-label="Скрыть пароль"
                            :error="!!profileForm.errors.current_password"
                        />
                    </NFormField>
                    <div class="profile__actions">
                        <span class="profile__meta"
                            >Последний вход:
                            {{ formatDateTime(profile.last_login_at) }}</span
                        >
                        <NButton
                            type="submit"
                            variant="primary"
                            :loading="profileForm.processing"
                            :disabled="!profileForm.isDirty"
                            >Сохранить</NButton
                        >
                    </div>
                </form>
            </NCard>

            <NCard padding="var(--kpi-pad)">
                <h2 class="profile__title">Пароль</h2>
                <form class="profile__form" @submit.prevent="savePassword">
                    <NFormField
                        label="Текущий пароль"
                        :error="passwordForm.errors.current_password"
                        required
                    >
                        <NInput
                            v-model="passwordForm.current_password"
                            type="password"
                            icon="lock"
                            autocomplete="current-password"
                            reveal-label="Показать пароль"
                            hide-label="Скрыть пароль"
                            :error="!!passwordForm.errors.current_password"
                        />
                    </NFormField>
                    <NFormField
                        label="Новый пароль"
                        :error="passwordForm.errors.password"
                        hint="Минимум 15 символов: заглавные и строчные буквы, цифры и спецсимволы."
                        required
                    >
                        <NInput
                            v-model="passwordForm.password"
                            type="password"
                            icon="lock"
                            autocomplete="new-password"
                            reveal-label="Показать пароль"
                            hide-label="Скрыть пароль"
                            :error="!!passwordForm.errors.password"
                        />
                    </NFormField>
                    <NFormField label="Повторите новый пароль" required>
                        <NInput
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            icon="lock"
                            autocomplete="new-password"
                            reveal-label="Показать пароль"
                            hide-label="Скрыть пароль"
                        />
                    </NFormField>
                    <div class="profile__actions">
                        <span class="profile__meta"
                            >Остальные сеансы завершатся после смены
                            пароля.</span
                        >
                        <NButton
                            type="submit"
                            variant="primary"
                            :loading="passwordForm.processing"
                            >Сменить пароль</NButton
                        >
                    </div>
                </form>
            </NCard>

            <NCard padding="var(--kpi-pad)">
                <div class="profile__head">
                    <h2 class="profile__title">Сеансы</h2>
                    <NButton
                        variant="secondary"
                        icon="log-out"
                        @click="openOthers"
                        >Выйти на других устройствах</NButton
                    >
                </div>
                <ul
                    v-if="sessionsSupported && sessions.length"
                    class="sessions"
                >
                    <li
                        v-for="s in sessions"
                        :key="s.key"
                        class="sessions__row"
                    >
                        <div class="sessions__info">
                            <b>{{ deviceLabel(s.agent) }}</b>
                            <span
                                >{{ s.ip || "IP неизвестен" }} ·
                                {{ formatRelative(s.last_active_at) }}</span
                            >
                        </div>
                        <NBadge v-if="s.current" tone="ok" pill>Текущий</NBadge>
                        <NButton
                            v-else
                            variant="ghost"
                            tone="danger"
                            size="sm"
                            @click="endKey = s.key"
                            >Завершить</NButton
                        >
                    </li>
                </ul>
                <NEmptyState
                    v-else
                    icon="lock"
                    title="Список сеансов недоступен"
                    description="Он работает с SESSION_DRIVER=database. Кнопка выше всё равно завершает остальные сеансы."
                />
            </NCard>
        </div>

        <NModal
            v-model="othersOpen"
            title="Выйти на других устройствах"
            width="420px"
            close-label="Закрыть"
        >
            <NFormField
                label="Текущий пароль"
                :error="othersForm.errors.password"
                hint="Текущий сеанс останется активным."
                required
            >
                <NInput
                    v-model="othersForm.password"
                    type="password"
                    icon="lock"
                    autocomplete="current-password"
                    :error="!!othersForm.errors.password"
                    @keyup.enter="logoutOthers"
                />
            </NFormField>
            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton
                    variant="danger"
                    block
                    :loading="othersForm.processing"
                    @click="logoutOthers"
                    >Завершить сеансы</NButton
                >
            </template>
        </NModal>

        <ConfirmModal
            :open="endKey !== null"
            :loading="ending"
            title="Завершить сеанс"
            message="Устройство выйдет из панели при следующем запросе."
            confirm-label="Завершить"
            @confirm="endSession"
            @cancel="endKey = null"
            @update:open="!$event && (endKey = null)"
        />
    </AdminLayout>
</template>

<style scoped>
.profile {
    max-width: 760px;
}
.profile__notice {
    padding: 13px 16px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    background: var(--warn-bg, var(--accent-soft));
    font-size: 13.5px;
}
.profile__title {
    margin: 0 0 16px;
    font-size: 16px;
    font-weight: 800;
}
.profile__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.profile__head .profile__title {
    margin: 0;
}
.profile__form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.profile__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.profile__meta {
    font-size: 12.5px;
    color: var(--text-3);
}
.sessions {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
}
.sessions__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 0;
    border-top: 1px solid var(--border);
}
.sessions__row:first-child {
    border-top: none;
}
.sessions__info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    font-size: 13.5px;
}
.sessions__info span {
    font-size: 12px;
    color: var(--text-3);
}
</style>
