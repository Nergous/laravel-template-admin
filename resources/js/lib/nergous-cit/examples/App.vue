<script setup>
// Demo showcase — drop into a fresh Vite Vue project as App.vue.
// Adjust the import path if you place the library elsewhere.
import { ref, reactive } from "vue";
import {
    NButton,
    NInput,
    NSelect,
    NTextarea,
    NSwitch,
    NCheckbox,
    NRadioGroup,
    NSegmented,
    NBadge,
    NAvatar,
    NAvatarGroup,
    NCard,
    NAlert,
    NTabs,
    NModal,
    NDrawer,
    NWizard,
    NAnchoredForm,
    NToaster,
    NIcon,
    useTheme,
    useToast,
} from "../index.js";

const { theme, toggle } = useTheme();
const toast = useToast();

const search = ref("");
const desc = ref("Операционная консоль для распределённых команд.");
const sw1 = ref(true);
const sw2 = ref(false);
const chk = ref(true);
const radio = ref("b");
const seg = ref("list");
const tab = ref("t1");
const role = ref("editor");
const modal = ref(false);
const drawer = ref(false);

const radioOpts = [
    { value: "a", label: "Вариант A" },
    { value: "b", label: "Вариант B" },
    { value: "c", label: "Вариант C" },
];
const segOpts = [
    { value: "list", label: "Список" },
    { value: "grid", label: "Сетка" },
    { value: "board", label: "Доска" },
];
const tabs = [
    { value: "t1", label: "Обзор" },
    { value: "t2", label: "Настройки" },
    { value: "t3", label: "История" },
];
const tabText = {
    t1: "Сводная информация и ключевые метрики раздела.",
    t2: "Параметры конфигурации и доступа.",
    t3: "Полный журнал изменений с фильтрами.",
};

// --- Большие формы: один блок с переключателем между мастером и якорной формой ---
const bigMode = ref("create");
const bigModeOpts = [
    { value: "create", label: "Создание · мастер" },
    { value: "edit", label: "Редактирование · якоря" },
];
const wizardStep = ref("basic");
const editSection = ref("basic");
const bigSteps = [
    { value: "basic", label: "Основное", sub: "Заголовок, тип, slug" },
    { value: "content", label: "Контент", sub: "Тело и формат" },
    { value: "taxonomy", label: "Категории", sub: "Категории, теги, связи" },
    { value: "media", label: "Медиа", sub: "Обложка и галерея" },
    { value: "seo", label: "SEO", sub: "Метаданные" },
    { value: "publish", label: "Публикация", sub: "Статус и доступ" },
];
const bigSections = [
    { value: "basic", label: "Основное", count: 4 },
    { value: "content", label: "Контент", count: 2 },
    { value: "taxonomy", label: "Категории", count: 3 },
    { value: "media", label: "Медиа", count: 2 },
    { value: "seo", label: "SEO", count: 3 },
    { value: "publish", label: "Публикация", count: 4 },
];
const typeOpts = [
    { value: "article", label: "Статья" },
    { value: "news", label: "Новость" },
    { value: "case", label: "Кейс" },
];
const formatOpts = [
    { value: "md", label: "Markdown" },
    { value: "html", label: "HTML" },
    { value: "rich", label: "Rich text" },
];
const statusOpts = [
    { value: "draft", label: "Черновик" },
    { value: "scheduled", label: "Запланирован" },
    { value: "published", label: "Опубликован" },
];
// Both shells edit the same conceptual record.
const post = reactive({
    title: "Запуск платформы 2.0",
    url: "zapusk-platformy-2-0",
    type: "article",
    format: "md",
    body: "## Что нового\n\nПереработали панель на единой дизайн-системе.",
    category: "product",
    tags: "запуск, платформа",
    metaTitle: "Запуск платформы 2.0 — nergous-cit",
    metaDesc: "Переработанная панель на единой дизайн-системе.",
    status: "scheduled",
    indexable: true,
});
</script>

<template>
    <div class="nergouscit-reset demo">
        <NToaster region-label="Уведомления" dismiss-label="Закрыть" />

        <header class="demo__top">
            <div class="demo__brand">
                <div class="demo__logo">C</div>
                <b>nergous-cit UI</b><NBadge tone="accent" pill>Vue 3</NBadge>
            </div>
            <NButton
                variant="secondary"
                :icon="theme === 'dark' ? 'sun' : 'moon'"
                @click="toggle"
            >
                {{ theme === "dark" ? "Светлая" : "Тёмная" }}
            </NButton>
        </header>

        <main class="demo__grid">
            <NCard>
                <h3 class="demo__h">Кнопки</h3>
                <div class="demo__row">
                    <NButton variant="primary" @click="toast.success('Готово')"
                        >Основная</NButton
                    >
                    <NButton variant="secondary">Вторичная</NButton>
                    <NButton variant="ghost">Призрачная</NButton>
                    <NButton variant="danger">Опасная</NButton>
                </div>
                <div class="demo__row">
                    <NButton icon="plus">С иконкой</NButton>
                    <NButton :loading="true">Загрузка</NButton>
                    <NButton :disabled="true">Disabled</NButton>
                </div>
            </NCard>

            <NCard>
                <h3 class="demo__h">Бейджи</h3>
                <div class="demo__row">
                    <NBadge tone="ok" dot>Активен</NBadge>
                    <NBadge tone="warn" dot>Ожидание</NBadge>
                    <NBadge tone="danger" dot>Ошибка</NBadge>
                    <NBadge tone="info">Info</NBadge>
                    <NBadge tone="accent" pill>Owner</NBadge>
                </div>
            </NCard>

            <NCard>
                <h3 class="demo__h">Поля ввода</h3>
                <div class="demo__col">
                    <NInput
                        v-model="search"
                        icon="search"
                        placeholder="Поиск"
                    />
                    <NSelect v-model="role">
                        <option value="admin">Admin</option>
                        <option value="editor">Editor</option>
                        <option value="viewer">Viewer</option>
                    </NSelect>
                    <NTextarea v-model="desc" :rows="2" />
                </div>
            </NCard>

            <NCard>
                <h3 class="demo__h">Переключатели</h3>
                <div class="demo__row demo__row--mid">
                    <NSwitch v-model="sw1" />
                    <NSwitch v-model="sw2" />
                    <NCheckbox v-model="chk">Чекбокс</NCheckbox>
                </div>
                <div class="demo__row demo__row--mid">
                    <NRadioGroup
                        v-model="radio"
                        :options="radioOpts"
                        aria-label="Демо-переключатель"
                    />
                </div>
                <NSegmented v-model="seg" :options="segOpts" />
            </NCard>

            <NCard>
                <h3 class="demo__h">Аватары</h3>
                <div class="demo__row demo__row--mid">
                    <NAvatar name="Анна Кириллова" :size="32" />
                    <NAvatar name="Мария Орлова" :size="42" status="online" />
                    <NAvatar name="Дмитрий Соколов" :size="50" status="busy" />
                    <NAvatarGroup
                        :items="[
                            'Анна Кириллова',
                            'Мария Орлова',
                            'Дмитрий Соколов',
                            'Игорь Лебедев',
                            'Ольга Новикова',
                        ]"
                        :max="3"
                    />
                </div>
            </NCard>

            <NCard>
                <h3 class="demo__h">Уведомления</h3>
                <div class="demo__col">
                    <NAlert tone="info" title="Информация."
                        >Нейтральное сообщение.</NAlert
                    >
                    <NAlert tone="ok" title="Успешно."
                        >Изменения сохранены.</NAlert
                    >
                    <NAlert tone="warn" title="Внимание."
                        >Проверьте данные.</NAlert
                    >
                </div>
                <div class="demo__row" style="margin-top: 14px">
                    <NButton
                        variant="secondary"
                        size="sm"
                        @click="toast.info('Инфо', 'Пример тоста')"
                        >Тост</NButton
                    >
                    <NButton
                        variant="secondary"
                        size="sm"
                        @click="toast.error('Ошибка', 'Что-то пошло не так')"
                        >Ошибка</NButton
                    >
                </div>
            </NCard>

            <NCard>
                <h3 class="demo__h">Вкладки</h3>
                <NTabs v-model="tab" :tabs="tabs" />
                <p class="demo__tabtext">{{ tabText[tab] }}</p>
            </NCard>

            <NCard>
                <h3 class="demo__h">Оверлеи</h3>
                <div class="demo__row">
                    <NButton variant="primary" @click="modal = true"
                        >Модалка</NButton
                    >
                    <NButton variant="secondary" @click="drawer = true"
                        >Drawer</NButton
                    >
                </div>
            </NCard>
        </main>

        <section class="demo__wide">
            <h3 class="demo__h">Большие формы</h3>
            <p class="demo__lead">
                Для крупной модели данных drawer и модалка тесны.
                <b>Создание</b> — пошаговый мастер с чекпойнтами.
                <b>Редактирование</b> — единая страница с якорной навигацией:
                разделы видны сразу, оглавление подсвечивает текущий.
            </p>

            <NSegmented
                v-model="bigMode"
                :options="bigModeOpts"
                aria-label="Тип большой формы"
                class="demo__bigswitch"
            />

            <NWizard
                v-if="bigMode === 'create'"
                v-model="wizardStep"
                :steps="bigSteps"
                title="Новая публикация"
                nav-label="Шаги создания"
            >
                <template #default="{ step }">
                    <div class="demo__panelTitle">{{ step.label }}</div>
                    <div class="demo__panelSub">{{ step.sub }}</div>
                    <div class="demo__col">
                        <template v-if="step.value === 'basic'">
                            <label class="demo__field"
                                ><span>Заголовок</span
                                ><NInput v-model="post.title"
                            /></label>
                            <label class="demo__field"
                                ><span>URL</span><NInput v-model="post.url"
                            /></label>
                            <div class="demo__field">
                                <span>Тип</span>
                                <NSegmented
                                    v-model="post.type"
                                    :options="typeOpts"
                                    aria-label="Тип"
                                />
                            </div>
                        </template>
                        <template v-else-if="step.value === 'content'">
                            <div class="demo__field">
                                <span>Формат</span>
                                <NSegmented
                                    v-model="post.format"
                                    :options="formatOpts"
                                    aria-label="Формат"
                                />
                            </div>
                            <label class="demo__field"
                                ><span>Тело</span
                                ><NTextarea v-model="post.body" :rows="5"
                            /></label>
                        </template>
                        <template v-else-if="step.value === 'taxonomy'">
                            <label class="demo__field"
                                ><span>Категория</span>
                                <NSelect v-model="post.category">
                                    <option value="product">Продукт</option>
                                    <option value="eng">Инженерия</option>
                                    <option value="news">Новости</option>
                                </NSelect>
                            </label>
                            <label class="demo__field"
                                ><span>Теги</span><NInput v-model="post.tags"
                            /></label>
                        </template>
                        <template v-else-if="step.value === 'media'">
                            <p class="demo__muted">
                                Обложка и галерея — JPG/PNG, рекоменд.
                                2560×1440.
                            </p>
                            <NButton variant="secondary" icon="upload"
                                >Загрузить обложку</NButton
                            >
                        </template>
                        <template v-else-if="step.value === 'seo'">
                            <label class="demo__field"
                                ><span>Meta title</span
                                ><NInput v-model="post.metaTitle"
                            /></label>
                            <label class="demo__field"
                                ><span>Meta description</span
                                ><NTextarea v-model="post.metaDesc" :rows="3"
                            /></label>
                        </template>
                        <template v-else-if="step.value === 'publish'">
                            <div class="demo__field">
                                <span>Статус</span>
                                <NSegmented
                                    v-model="post.status"
                                    :options="statusOpts"
                                    aria-label="Статус"
                                />
                            </div>
                            <div class="demo__switchRow">
                                <div>
                                    <b>Индексация поисковиками</b>
                                    <span class="demo__muted"
                                        >Разрешить роботам индексировать
                                        страницу</span
                                    >
                                </div>
                                <NSwitch v-model="post.indexable" />
                            </div>
                        </template>
                    </div>
                </template>

                <template
                    #footer="{ index, count, isFirst, isLast, prev, next }"
                >
                    <span class="demo__wizstep"
                        >Шаг {{ index + 1 }} / {{ count }}</span
                    >
                    <span class="demo__grow" />
                    <NButton
                        variant="secondary"
                        @click="toast.info('Черновик сохранён')"
                        >Сохранить черновик</NButton
                    >
                    <NButton variant="ghost" :disabled="isFirst" @click="prev"
                        >Назад</NButton
                    >
                    <NButton
                        variant="primary"
                        @click="
                            isLast
                                ? toast.success('Публикация создана')
                                : next()
                        "
                        >{{ isLast ? "Создать публикацию" : "Далее" }}</NButton
                    >
                </template>
            </NWizard>

            <NAnchoredForm
                v-else
                v-model="editSection"
                :sections="bigSections"
                sections-label="Разделы"
                nav-label="Разделы публикации"
                height="520px"
            >
                <template #header>
                    <div class="demo__rec">
                        <NAvatar name="Запуск платформы" :size="38" />
                        <div class="demo__recMeta">
                            <b>Запуск платформы 2.0</b>
                            <span class="demo__muted">Публикация · #128</span>
                        </div>
                    </div>
                </template>

                <template #section-basic>
                    <div class="demo__col">
                        <label class="demo__field"
                            ><span>Заголовок</span><NInput v-model="post.title"
                        /></label>
                        <label class="demo__field"
                            ><span>URL</span><NInput v-model="post.url"
                        /></label>
                    </div>
                </template>
                <template #section-content>
                    <label class="demo__field"
                        ><span>Тело · Markdown</span
                        ><NTextarea v-model="post.body" :rows="4"
                    /></label>
                </template>
                <template #section-taxonomy>
                    <div class="demo__col">
                        <label class="demo__field"
                            ><span>Категория</span>
                            <NSelect v-model="post.category">
                                <option value="product">Продукт</option>
                                <option value="eng">Инженерия</option>
                                <option value="news">Новости</option>
                            </NSelect>
                        </label>
                        <label class="demo__field"
                            ><span>Теги</span><NInput v-model="post.tags"
                        /></label>
                    </div>
                </template>
                <template #section-media>
                    <NButton variant="secondary" icon="upload"
                        >Заменить обложку</NButton
                    >
                </template>
                <template #section-seo>
                    <div class="demo__col">
                        <label class="demo__field"
                            ><span>Meta title</span
                            ><NInput v-model="post.metaTitle"
                        /></label>
                        <label class="demo__field"
                            ><span>Meta description</span
                            ><NTextarea v-model="post.metaDesc" :rows="3"
                        /></label>
                    </div>
                </template>
                <template #section-publish>
                    <div class="demo__col">
                        <div class="demo__field">
                            <span>Статус</span>
                            <NSegmented
                                v-model="post.status"
                                :options="statusOpts"
                                aria-label="Статус"
                            />
                        </div>
                        <div class="demo__switchRow">
                            <div>
                                <b>Индексация поисковиками</b>
                                <span class="demo__muted"
                                    >Разрешить роботам индексировать</span
                                >
                            </div>
                            <NSwitch v-model="post.indexable" />
                        </div>
                    </div>
                </template>

                <template #status>
                    <span class="demo__draft"
                        ><span class="demo__draftDot" />Черновик · не
                        сохранено</span
                    >
                </template>
                <template #savebar>
                    <span class="demo__muted">Несохранённые изменения</span>
                    <NButton variant="secondary" @click="toast.info('Отменено')"
                        >Отмена</NButton
                    >
                    <NButton
                        variant="primary"
                        @click="toast.success('Сохранено')"
                        >Сохранить</NButton
                    >
                </template>
            </NAnchoredForm>
        </section>

        <NModal
            v-model="modal"
            title="Пригласить участника"
            close-label="Закрыть"
        >
            <div class="demo__col">
                <NInput icon="mail" placeholder="name@company.com" />
                <NSelect
                    ><option>Editor</option>
                    <option>Viewer</option></NSelect
                >
            </div>
            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton
                    variant="primary"
                    block
                    @click="
                        () => {
                            close();
                            toast.success('Отправлено');
                        }
                    "
                    >Отправить</NButton
                >
            </template>
        </NModal>

        <NDrawer v-model="drawer" title="Профиль" close-label="Закрыть">
            <div class="demo__col">
                <NAvatar name="Анна Кириллова" :size="52" />
                <NInput :model-value="'Анна Кириллова'" />
                <NInput :model-value="'anna@nergous-cit.app'" />
            </div>
            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Закрыть</NButton
                >
                <NButton
                    variant="primary"
                    block
                    @click="
                        () => {
                            close();
                            toast.success('Сохранено');
                        }
                    "
                    >Сохранить</NButton
                >
            </template>
        </NDrawer>
    </div>
</template>

<style scoped>
.demo {
    min-height: 100vh;
    padding: 0 0 60px;
}
.demo__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 64px;
    padding: 0 28px;
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    background: var(--surface);
    z-index: 10;
}
.demo__brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 16px;
    letter-spacing: -0.01em;
}
.demo__logo {
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: linear-gradient(140deg, var(--accent), #8b8ffb);
    color: #fff;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
}
.demo__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 18px;
    padding: 24px 28px;
    max-width: 1200px;
}
.demo__h {
    margin: 0 0 16px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-3);
}
.demo__row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.demo__row + .demo__row {
    margin-top: 11px;
}
.demo__row--mid {
    align-items: center;
    gap: 18px;
    margin-bottom: 16px;
}
.demo__col {
    display: flex;
    flex-direction: column;
    gap: 11px;
}
.demo__tabtext {
    font-size: 14px;
    color: var(--text-2);
    line-height: 1.6;
    margin: 16px 0 0;
}

/* --- Большие формы --- */
.demo__wide {
    max-width: 1200px;
    padding: 4px 28px 32px;
}
.demo__lead {
    margin: 0 0 16px;
    max-width: 660px;
    font-size: 13.5px;
    line-height: 1.55;
    color: var(--text-2);
}
.demo__lead b {
    color: var(--text);
}
.demo__bigswitch {
    margin-bottom: 16px;
}
.demo__panelTitle {
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.01em;
    color: var(--text);
}
.demo__panelSub {
    margin: 2px 0 18px;
    font-size: 12.5px;
    color: var(--text-3);
}
.demo__field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.demo__field > span:first-child {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-2);
}
.demo__muted {
    font-size: 12.5px;
    color: var(--text-3);
}
.demo__switchRow {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
}
.demo__switchRow b {
    display: block;
    font-size: 13.5px;
    color: var(--text);
}
.demo__switchRow .demo__muted {
    display: block;
    margin-top: 2px;
}
.demo__wizstep {
    font-size: 12.5px;
    color: var(--text-3);
    font-family: var(--font-mono);
}
.demo__grow {
    flex: 1;
}
.demo__rec {
    display: flex;
    align-items: center;
    gap: 11px;
}
.demo__recMeta {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.2;
}
.demo__recMeta b {
    font-size: 13.5px;
    color: var(--text);
}
.demo__draft {
    display: flex;
    align-items: center;
    gap: 8px;
}
.demo__draftDot {
    width: 7px;
    height: 7px;
    flex: none;
    border-radius: 50%;
    background: var(--warn);
}
</style>
