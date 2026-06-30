<script setup>
import { computed } from "vue";
import { Head } from "@inertiajs/vue3";
import { NButton, NIcon } from "@/lib/nergous-cit";

// The server handler (bootstrap/app.php) renders this page when debug is off
// for 403/404/419/429/500/503. The labels live here (the DS is locale-agnostic),
// the status arrives as a prop.
const props = defineProps({
    status: { type: Number, default: 500 },
});

const MAP = {
    403: {
        icon: "lock",
        title: "Доступ запрещён",
        text: "У вашей роли нет прав на этот раздел. Обратитесь к администратору, если доступ нужен.",
    },
    404: {
        icon: "search",
        title: "Страница не найдена",
        text: "Запрошенная страница не существует или была перемещена.",
    },
    419: {
        icon: "shield",
        title: "Сессия истекла",
        text: "Страница устарела из соображений безопасности. Войдите снова и повторите действие.",
    },
    429: {
        icon: "activity",
        title: "Слишком много запросов",
        text: "Вы отправляете запросы слишком часто. Подождите немного и попробуйте снова.",
    },
    500: {
        icon: "bolt",
        title: "Что-то пошло не так",
        text: "На сервере произошла ошибка. Мы уже знаем о ней — попробуйте обновить страницу позже.",
    },
    503: {
        icon: "settings",
        title: "Идут технические работы",
        text: "Сервис временно недоступен. Скоро всё заработает — загляните чуть позже.",
    },
};

const info = computed(() => MAP[props.status] ?? MAP[500]);
const home = computed(() => (props.status === 419 ? "/admin/login" : "/admin"));
const homeLabel = computed(() =>
    props.status === 419 ? "Войти снова" : "На главную",
);
</script>

<template>
    <Head :title="`${status} — ${info.title}`" />

    <main class="errpage">
        <div class="errpage__box">
            <div class="errpage__icon" aria-hidden="true">
                <NIcon :name="info.icon" :size="28" />
            </div>
            <div class="errpage__code">{{ status }}</div>
            <h1 class="errpage__title">{{ info.title }}</h1>
            <p class="errpage__text">{{ info.text }}</p>
            <div class="errpage__actions">
                <NButton as="a" :href="home" variant="primary" size="lg">
                    {{ homeLabel }}
                </NButton>
            </div>
        </div>
    </main>
</template>

<style scoped>
.errpage {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--sp-6, 32px);
    background: var(--bg, var(--surface));
}
.errpage__box {
    width: 100%;
    max-width: 460px;
    padding: 40px 32px;
    text-align: center;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg, 16px);
    box-shadow: var(--shadow-lg);
}
.errpage__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    margin-bottom: 18px;
    border-radius: 16px;
    color: var(--accent-ink, var(--accent));
    background: var(--accent-soft);
}
.errpage__code {
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.18em;
    color: var(--text-3);
}
.errpage__title {
    margin: 6px 0 10px;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--text);
}
.errpage__text {
    margin: 0 0 26px;
    font-size: 14px;
    line-height: 1.55;
    color: var(--text-3);
}
.errpage__actions {
    display: flex;
    justify-content: center;
    gap: 12px;
}
</style>
