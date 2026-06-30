<script setup>
import { computed } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
import AuthLayout from "@/admin/layouts/AuthLayout.vue";
import {
    NBrand,
    NInput,
    NCheckbox,
    NButton,
    NFormField,
} from "@/lib/nergous-cit";

// Read the globally-shared appName (HandleInertiaRequests) so the brand matches
// the rest of the app from a single source instead of a page-local prop.
const appName = computed(() => usePage().props.appName || "Admin");

const form = useForm({
    email: "",
    password: "",
    remember: false,
});

function submit() {
    form.post("/admin/login", {
        onFinish: () => form.reset("password"),
    });
}
</script>

<template>
    <AuthLayout title="Вход">
        <form class="login" @submit.prevent="submit">
            <NBrand
                class="login__brand"
                :glyph="appName.charAt(0).toUpperCase()"
                :name="appName"
                sub="Панель администрирования"
                size="md"
            />

            <h1 class="login__title">Вход в панель</h1>
            <p class="login__lead">
                Введите данные для доступа к рабочему пространству
            </p>

            <NFormField
                class="login__field"
                label="Email"
                :error="form.errors.email"
            >
                <NInput
                    v-model="form.email"
                    type="email"
                    icon="mail"
                    size="lg"
                    placeholder="you@example.com"
                    autocomplete="username"
                />
            </NFormField>

            <NFormField
                class="login__field"
                label="Пароль"
                :error="form.errors.password"
            >
                <NInput
                    v-model="form.password"
                    type="password"
                    icon="lock"
                    size="lg"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    reveal-label="Показать пароль"
                    hide-label="Скрыть пароль"
                />
            </NFormField>

            <div class="login__row">
                <NCheckbox v-model="form.remember">Запомнить меня</NCheckbox>
            </div>

            <NButton
                type="submit"
                variant="primary"
                size="lg"
                block
                :loading="form.processing"
            >
                {{ form.processing ? "Вход…" : "Войти" }}
            </NButton>
        </form>

        <template #aside>
            <div class="aside__kick">Панель администрирования</div>
            <div class="aside__title">
                Управление данными сайта {{ appName }}
            </div>
        </template>
    </AuthLayout>
</template>

<style scoped>
.login__brand {
    margin-bottom: 30px;
}
.login__title {
    margin: 0 0 6px;
    font-size: 25px;
    font-weight: 800;
    letter-spacing: -0.03em;
    color: var(--text);
}
.login__lead {
    margin: 0 0 26px;
    font-size: 14px;
    color: var(--text-3);
}
.login__field {
    margin-bottom: 15px;
}
.login__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-top: 2px;
    margin-bottom: 18px;
}
/* Right marketing panel — sits on a fixed indigo gradient, so colors are
   intentionally literal (white / translucent white) rather than themed. */
.aside__kick {
    margin-bottom: 14px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    opacity: 0.8;
}
.aside__title {
    margin-bottom: 16px;
    font-size: 30px;
    font-weight: 800;
    letter-spacing: -0.025em;
    line-height: 1.18;
}
</style>
