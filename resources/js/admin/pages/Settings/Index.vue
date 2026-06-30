<script setup>
import { ref, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NTabs,
    NCard,
    NInput,
    NTextarea,
    NSelect,
    NSwitch,
    NButton,
    NIcon,
    NModal,
    NFormField,
} from "@/lib/nergous-cit";

// settings: { general:{app_name,timezone}, seo:{...}, security:{...} }
const props = defineProps({
    settings: { type: Object, required: true },
    images: { type: Array, default: () => [] }, // media library images for the OG picker
});

// useForm wraps the whole nested payload — submit shape matches the contract.
const form = useForm({ settings: props.settings });

const tab = ref("general");
const tabs = [
    { value: "general", label: "Общие" },
    { value: "seo", label: "SEO" },
    { value: "security", label: "Безопасность" },
];

// ARIA link tab ↔ panel: NTabs with id-base="settings" gives tabs the id
// `settings-${value}`, and the panel references it via aria-labelledby.
const panelProps = (value) => ({
    id: `settings-panel-${value}`,
    role: "tabpanel",
    "aria-labelledby": `settings-${value}`,
});

const timezoneOptions = [
    { value: "UTC", label: "UTC" },
    { value: "Europe/Moscow", label: "Europe/Moscow (MSK)" },
    { value: "Europe/Kyiv", label: "Europe/Kyiv" },
    { value: "Europe/London", label: "Europe/London" },
    { value: "Europe/Berlin", label: "Europe/Berlin" },
    { value: "America/New_York", label: "America/New_York" },
    { value: "Asia/Almaty", label: "Asia/Almaty" },
];

function save() {
    form.put("/admin/settings", { preserveScroll: true });
}

function cancel() {
    form.reset();
}

/* ---------- Picking an image from the media library (OG + favicon) ---------- */
const pickerOpen = ref(false);
// Which settings field we are currently picking for.
const pickerTarget = ref({
    group: "seo",
    key: "og_image",
    title: "OG-изображения",
});

function openPicker(group, key, title) {
    pickerTarget.value = { group, key, title };
    pickerOpen.value = true;
}
function selectImage(img) {
    const { group, key } = pickerTarget.value;
    form.settings[group][key] = img.url;
    pickerOpen.value = false;
}
function clearImage(group, key) {
    form.settings[group][key] = "";
}
const pickedUrl = computed(() => {
    const { group, key } = pickerTarget.value;
    return form.settings[group]?.[key] ?? "";
});
</script>

<template>
    <AdminLayout title="Настройки" subtitle="Конфигурация и SEO">
        <div class="settings">
            <NTabs
                v-model="tab"
                :tabs="tabs"
                id-base="settings"
                class="settings__tabs"
                aria-label="Разделы настроек"
            />

            <!-- GENERAL -->
            <NCard v-show="tab === 'general'" v-bind="panelProps('general')">
                <header class="card-head">
                    <span class="card-head__icon">
                        <NIcon name="settings" :size="20" />
                    </span>
                    <div class="card-head__text">
                        <h2 class="card-head__title">Основные</h2>
                        <div class="card-head__sub">
                            Название приложения и часовой пояс
                        </div>
                    </div>
                </header>

                <div class="card-body">
                    <NFormField
                        label="Название приложения"
                        :error="form.errors['settings.general.app_name']"
                    >
                        <NInput
                            v-model="form.settings.general.app_name"
                            placeholder="nergous-cit"
                        />
                    </NFormField>

                    <NFormField
                        label="Часовой пояс"
                        :error="form.errors['settings.general.timezone']"
                        tag="div"
                    >
                        <NSelect
                            v-model="form.settings.general.timezone"
                            :options="timezoneOptions"
                            :error="!!form.errors['settings.general.timezone']"
                        />
                    </NFormField>

                    <NFormField
                        label="Favicon"
                        hint="Иконка вкладки браузера — выбирается из медиатеки"
                        tag="div"
                    >
                        <div class="og-picker">
                            <div
                                v-if="form.settings.general.favicon"
                                class="og-picker__preview og-picker__preview--fav"
                            >
                                <img
                                    :src="form.settings.general.favicon"
                                    alt="Favicon"
                                />
                            </div>
                            <div
                                v-else
                                class="og-picker__empty og-picker__empty--fav"
                            >
                                <NIcon name="asset" :size="18" />
                            </div>
                            <div class="og-picker__actions">
                                <NButton
                                    variant="secondary"
                                    type="button"
                                    @click="
                                        openPicker(
                                            'general',
                                            'favicon',
                                            'favicon',
                                        )
                                    "
                                >
                                    {{
                                        form.settings.general.favicon
                                            ? "Изменить"
                                            : "Выбрать"
                                    }}
                                </NButton>
                                <NButton
                                    v-if="form.settings.general.favicon"
                                    variant="ghost"
                                    tone="danger"
                                    type="button"
                                    @click="clearImage('general', 'favicon')"
                                >
                                    Убрать
                                </NButton>
                            </div>
                        </div>
                    </NFormField>
                </div>
            </NCard>

            <!-- SEO -->
            <NCard v-show="tab === 'seo'" v-bind="panelProps('seo')">
                <header class="card-head">
                    <span class="card-head__icon">
                        <NIcon name="globe" :size="20" />
                    </span>
                    <div class="card-head__text">
                        <h2 class="card-head__title">SEO и индексация</h2>
                        <div class="card-head__sub">
                            Мета-теги, карта сайта, доступ роботов
                        </div>
                    </div>
                </header>

                <div class="card-body">
                    <NFormField
                        label="Шаблон meta-заголовка"
                        hint="%s заменяется названием страницы"
                        :error="form.errors['settings.seo.meta_title_template']"
                    >
                        <NInput
                            v-model="form.settings.seo.meta_title_template"
                            placeholder="%s — nergous-cit"
                        />
                    </NFormField>

                    <NFormField
                        label="Meta-описание"
                        :error="form.errors['settings.seo.meta_description']"
                    >
                        <NTextarea
                            v-model="form.settings.seo.meta_description"
                            :rows="3"
                            placeholder="Краткое описание сайта для поисковой выдачи"
                        />
                    </NFormField>

                    <NFormField
                        label="Канонический домен"
                        :error="form.errors['settings.seo.canonical_domain']"
                    >
                        <NInput
                            v-model="form.settings.seo.canonical_domain"
                            placeholder="https://nergous-cit.app"
                        />
                    </NFormField>

                    <NFormField
                        label="OG-изображение"
                        hint="Превью при шаринге ссылки в соцсетях — выбирается из медиатеки"
                        tag="div"
                    >
                        <div class="og-picker">
                            <div
                                v-if="form.settings.seo.og_image"
                                class="og-picker__preview"
                            >
                                <img
                                    :src="form.settings.seo.og_image"
                                    alt="OG-изображение"
                                />
                            </div>
                            <div v-else class="og-picker__empty">
                                <NIcon name="asset" :size="22" />
                                <span>Изображение не выбрано</span>
                            </div>
                            <div class="og-picker__actions">
                                <NButton
                                    variant="secondary"
                                    type="button"
                                    @click="
                                        openPicker(
                                            'seo',
                                            'og_image',
                                            'OG-изображения',
                                        )
                                    "
                                >
                                    {{
                                        form.settings.seo.og_image
                                            ? "Изменить"
                                            : "Выбрать"
                                    }}
                                </NButton>
                                <NButton
                                    v-if="form.settings.seo.og_image"
                                    variant="ghost"
                                    tone="danger"
                                    type="button"
                                    @click="clearImage('seo', 'og_image')"
                                >
                                    Убрать
                                </NButton>
                            </div>
                        </div>
                    </NFormField>

                    <div class="toggle-list">
                        <label class="toggle-row">
                            <div class="toggle-row__text">
                                <b>Индексация поисковиками</b>
                                <span>robots: index / noindex</span>
                            </div>
                            <NSwitch
                                v-model="form.settings.seo.indexable"
                                aria-label="Индексация поисковиками"
                            />
                        </label>

                        <label class="toggle-row">
                            <div class="toggle-row__text">
                                <b>Карта сайта (sitemap.xml)</b>
                                <span>Автогенерация</span>
                            </div>
                            <NSwitch
                                v-model="form.settings.seo.sitemap"
                                aria-label="Карта сайта (sitemap.xml)"
                            />
                        </label>
                    </div>
                </div>
            </NCard>

            <!-- SECURITY -->
            <NCard v-show="tab === 'security'" v-bind="panelProps('security')">
                <header class="card-head">
                    <span class="card-head__icon">
                        <NIcon name="lock" :size="20" />
                    </span>
                    <div class="card-head__text">
                        <h2 class="card-head__title">Безопасность</h2>
                        <div class="card-head__sub">
                            Время жизни сессии и лимит попыток входа
                        </div>
                    </div>
                </header>

                <div class="card-body">
                    <div class="grid-2">
                        <NFormField
                            label="Время жизни сессии, мин"
                            :error="
                                form.errors[
                                    'settings.security.session_lifetime'
                                ]
                            "
                        >
                            <NInput
                                v-model="
                                    form.settings.security.session_lifetime
                                "
                                type="number"
                                placeholder="120"
                            />
                        </NFormField>

                        <NFormField
                            label="Лимит попыток входа"
                            :error="
                                form.errors['settings.security.login_throttle']
                            "
                        >
                            <NInput
                                v-model="form.settings.security.login_throttle"
                                type="number"
                                placeholder="5"
                            />
                        </NFormField>
                    </div>
                </div>
            </NCard>
        </div>

        <!-- Floating save bar — shown only while the form has unsaved changes. -->
        <Transition name="savebar">
            <div v-if="form.isDirty" class="savebar">
                <div class="savebar__pill">
                    <span class="savebar__hint">Несохранённые изменения</span>
                    <NButton
                        variant="ghost"
                        :disabled="form.processing"
                        @click="cancel"
                    >
                        Отмена
                    </NButton>
                    <NButton
                        variant="primary"
                        :loading="form.processing"
                        @click="save"
                    >
                        Сохранить
                    </NButton>
                </div>
            </div>
        </Transition>

        <!-- Image picker from the media library (OG / favicon) -->
        <NModal
            v-model="pickerOpen"
            :title="`Выбор ${pickerTarget.title}`"
            width="680px"
            close-label="Закрыть"
        >
            <div v-if="images.length" class="og-grid">
                <button
                    v-for="img in images"
                    :key="img.id"
                    type="button"
                    class="og-grid__item"
                    :class="{ on: pickedUrl === img.url }"
                    :aria-pressed="pickedUrl === img.url"
                    :aria-label="
                        img.original_name || 'Изображение из медиатеки'
                    "
                    @click="selectImage(img)"
                >
                    <img :src="img.thumb_url" alt="" loading="lazy" />
                </button>
            </div>
            <div v-else class="og-empty">
                В медиатеке пока нет изображений. Загрузите их в разделе
                «Медиатека».
            </div>
        </NModal>
    </AdminLayout>
</template>

<style scoped>
.settings {
    display: flex;
    flex-direction: column;
    gap: 22px;
    max-width: 940px;
    /* leave room so the floating bar never covers the last fields */
    padding-bottom: 96px;
}
.settings__tabs {
    margin-bottom: 2px;
}

/* --- Card icon header --- */
.card-head {
    display: flex;
    align-items: center;
    gap: 13px;
    margin-bottom: 22px;
}
.card-head__icon {
    width: 40px;
    height: 40px;
    border-radius: 11px;
    background: var(--accent-soft);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
}
.card-head__title {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.01em;
    color: var(--text);
}
.card-head__sub {
    font-size: 12.5px;
    color: var(--text-3);
    margin-top: 2px;
}

/* --- Card body layout --- */
.card-body {
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* --- Label-left / switch-right toggle rows --- */
.toggle-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 0;
    border-top: 1px solid var(--border);
    /* whole row is a <label> — clicking the caption toggles the switch */
    cursor: pointer;
}
.toggle-row:first-child {
    border-top: none;
}
.toggle-row__text b {
    display: block;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text);
}
.toggle-row__text span {
    display: block;
    font-size: 12px;
    color: var(--text-3);
    margin-top: 2px;
}

/* --- OG image picker --- */
.og-picker {
    display: flex;
    align-items: center;
    gap: 14px;
}
.og-picker__preview {
    width: 120px;
    height: 75px; /* ~16/10 */
    border-radius: var(--radius-md);
    overflow: hidden;
    border: 1px solid var(--border);
    background: var(--surface-3);
    flex: none;
}
.og-picker__preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.og-picker__empty {
    width: 120px;
    height: 75px;
    border-radius: var(--radius-md);
    border: 1px dashed var(--border-2);
    background: var(--surface-2);
    color: var(--text-3);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    font-size: 11.5px;
    text-align: center;
    flex: none;
}
.og-picker__preview--fav,
.og-picker__empty--fav {
    width: 64px;
    height: 64px;
}
.og-picker__actions {
    display: flex;
    gap: 8px;
}

/* --- OG picker grid (modal) --- */
.og-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
    max-height: 56vh;
    overflow-y: auto;
}
.og-grid__item {
    position: relative;
    aspect-ratio: 16 / 10;
    padding: 0;
    border: 2px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    background: var(--surface-3);
    cursor: pointer;
    transition: border-color 0.14s ease;
}
.og-grid__item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.og-grid__item:hover {
    border-color: var(--border-2);
}
.og-grid__item.on {
    border-color: var(--accent);
}
/* Non-color selected indicator (WCAG 1.4.1): a checkmark badge, not just the
   accent border. aria-pressed already conveys it to screen readers. */
.og-grid__item.on::after {
    content: "✓";
    position: absolute;
    top: 6px;
    right: 6px;
    width: 18px;
    height: 18px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--accent);
    color: var(--accent-on);
    font-size: 12px;
    font-weight: 800;
    line-height: 1;
}
.og-empty {
    padding: 32px;
    text-align: center;
    color: var(--text-3);
    font-size: 13.5px;
}

/* --- Floating save bar (DS pattern) --- */
.savebar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 22px;
    display: flex;
    justify-content: center;
    pointer-events: none;
    z-index: 1050;
}
.savebar__pill {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 12px 10px 20px;
    background: var(--surface);
    border: 1px solid var(--border-2);
    border-radius: 14px;
    box-shadow: var(--shadow-lg);
    pointer-events: auto;
}
.savebar__hint {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-2);
}

.savebar-enter-active,
.savebar-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}
.savebar-enter-from,
.savebar-leave-to {
    opacity: 0;
    transform: translateY(16px);
}
/* Drop the slide for users who ask for reduced motion — keep the opacity fade. */
@media (prefers-reduced-motion: reduce) {
    .savebar-enter-active,
    .savebar-leave-active {
        transition: opacity 0.2s ease;
    }
    .savebar-enter-from,
    .savebar-leave-to {
        transform: none;
    }
}

@media (max-width: 720px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
    .savebar {
        bottom: 14px;
        padding: 0 12px;
    }
    .savebar__pill {
        width: 100%;
        max-width: 440px;
        justify-content: center;
    }
}
</style>
