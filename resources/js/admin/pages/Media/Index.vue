<script setup>
import { ref, computed, onBeforeUnmount } from "vue";
import { router } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NCard,
    NDropzone,
    NProgress,
    NSpinner,
    NPagination,
    NSegmented,
    NButton,
    NCheckbox,
    NIcon,
    NEmptyState,
    NLightbox,
    useToast,
} from "@/lib/nergous-cit";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { can } from "@/lib/can.js";
import { formatBytes, pluralize } from "@/lib/format.js";

const props = defineProps({
    // Inertia-пагинатор. Каждая строка: { id, filename, original_name, mime_type,
    // type ('image'|'video'|'audio'|'document'|'other'), size (bytes), url, thumb_url, created_at }.
    media: { type: Object, required: true },
});

const toast = useToast();

/* ── Локальный список строк ──────────────────────────────────────────────
   Стартует с серверной страницы; в него прибавляются медиа, дозагруженные
   поллингом после загрузки (prepend), и из него удаляются стёртые элементы. */
const rows = ref([...props.media.data]);

/* ── Подписи и иконки по типу ─────────────────────────────────────────── */
const TYPE_LABEL = {
    image: "Фото",
    video: "Видео",
    audio: "Аудио",
    document: "Документ",
    other: "Файл",
};
// Иконки ограничены набором NIcon — берём ближайшие осмысленные.
const TYPE_ICON = {
    image: "asset",
    video: "layers",
    audio: "activity",
    document: "copy",
    other: "asset",
};
function typeLabel(m) {
    return TYPE_LABEL[m.type] || TYPE_LABEL.other;
}
function typeIcon(m) {
    return TYPE_ICON[m.type] || TYPE_ICON.other;
}
// Бейдж-метка вида JPG / MP4 / PDF — из расширения имени, иначе из subtype MIME.
function typeBadge(m) {
    const name = m.original_name || m.filename || "";
    const ext = name.includes(".") ? name.split(".").pop() : "";
    if (ext) return ext.toUpperCase().slice(0, 5);
    const sub = (m.mime_type || "").split("/")[1] || m.type || "";
    return sub.toUpperCase().slice(0, 5);
}

/* ── Фильтр по типу + переключатель вида ──────────────────────────────── */
const filter = ref("all");
const filterOpts = [
    { value: "all", label: "Все" },
    { value: "image", label: "Фото" },
    { value: "video", label: "Видео" },
    { value: "audio", label: "Аудио" },
    { value: "document", label: "Документы" },
];
const view = ref("grid");
const viewOpts = [
    { value: "grid", icon: "grid", label: "Сетка" },
    { value: "list", icon: "list", label: "Список" },
];

const visible = computed(() =>
    filter.value === "all"
        ? rows.value
        : rows.value.filter((m) => m.type === filter.value),
);
// Лайтбокс работает только по картинкам — индексы считаем внутри их подмножества.
const images = computed(() => visible.value.filter((m) => m.type === "image"));
// Нейтральная форма элемента для NLightbox: { url, caption }.
const lbItems = computed(() =>
    images.value.map((m) => ({
        url: m.url,
        caption: m.original_name || m.filename,
    })),
);

/* ── Загрузка: две фазы — «Загрузка» (реальные байты XHR) → «Обработка»
   (индетерминантный индикатор, пока поллинг тянет обработанные очередью
   файлы). Маленькие файлы заливаются мгновенно, реальная работа (генерация
   превью) идёт в очереди — поэтому статичные 100% больше не показываем. */
const uploading = ref(false);
const phase = ref("uploading"); // 'uploading' | 'processing'
const pct = ref(0);
const expectedCount = ref(0); // сколько файлов поставлено в очередь
const receivedCount = ref(0); // сколько уже подтянулось поллингом
let pollTimer = null;

// Сколько файлов ещё в обработке (для подписи), но не меньше нуля.
const processingLeft = computed(() =>
    Math.max(0, expectedCount.value - receivedCount.value),
);
const processingLabel = computed(() => {
    const n = processingLeft.value;
    if (n <= 0) return "Обработка файлов…";
    return `Обработка ${n} ${pluralize(n, "файла", "файлов", "файлов")}…`;
});

function largestId() {
    return rows.value.reduce((max, m) => (m.id > max ? m.id : max), 0);
}

function upload(files) {
    if (!files?.length) return;
    // Отменяем поллинг прошлой загрузки, иначе быстрый повторный drop оставит
    // осиротевший таймер, а его счётчики затрутся новой партией.
    stopPolling();
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const fd = new FormData();
    files.forEach((f) => fd.append("media[]", f));

    uploading.value = true;
    phase.value = "uploading";
    pct.value = 0;
    expectedCount.value = 0;
    receivedCount.value = 0;

    const xhr = new XMLHttpRequest();
    xhr.open("POST", "/admin/media");
    xhr.setRequestHeader("X-CSRF-TOKEN", token || "");
    xhr.setRequestHeader("Accept", "application/json");
    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable)
            pct.value = Math.round((e.loaded / e.total) * 100);
    };
    xhr.onload = () => {
        // onload срабатывает на любой завершённый ответ — 4xx/5xx тоже сюда.
        if (xhr.status < 200 || xhr.status >= 300) {
            uploadFailed(xhr);
            return;
        }
        // Байты доставлены — реальная работа теперь в очереди.
        pct.value = 100;
        let queued = files.length;
        try {
            const json = JSON.parse(xhr.responseText || "{}");
            if (typeof json.queued === "number") queued = json.queued;
        } catch {
            /* без JSON — используем число выбранных файлов как ожидание */
        }
        // Переходим во вторую фазу: индетерминантная «Обработка…».
        phase.value = "processing";
        startPolling(queued);
    };
    xhr.onerror = () => uploadFailed();
    xhr.send(fd);
}

// Сообщить об ошибке загрузки и сбросить индикатор. Текст берём из JSON-ответа
// сервера (валидация/лимит), иначе — общее сообщение.
function uploadFailed(xhr) {
    let msg = "Проверьте размер и формат файлов и попробуйте снова.";
    try {
        const json = JSON.parse(xhr?.responseText || "{}");
        if (json.message) msg = json.message;
    } catch {
        /* нет JSON — оставляем общее сообщение */
    }
    stopPolling();
    toast.error("Не удалось загрузить файлы", msg);
}

// Опрашиваем /poll до тех пор, пока не подтянутся все ожидаемые медиа
// или пока не исчерпаем лимит попыток (очередь могла отбраковать файл).
function startPolling(expected) {
    expectedCount.value = expected;
    receivedCount.value = 0;
    let attempts = 0;
    const MAX_ATTEMPTS = 20;

    const tick = async () => {
        attempts += 1;
        const after = largestId();
        try {
            const res = await fetch(`/admin/media/poll?after_id=${after}`, {
                headers: { Accept: "application/json" },
            });
            const fresh = await res.json(); // newest first
            if (Array.isArray(fresh) && fresh.length) {
                // poll отдаёт по убыванию id — кладём в начало в порядке возрастания.
                const known = new Set(rows.value.map((m) => m.id));
                const add = fresh.filter((m) => !known.has(m.id));
                for (const m of [...add].reverse()) rows.value.unshift(m);
                receivedCount.value += add.length;
            }
        } catch {
            /* временная сетевая ошибка — попробуем на следующем тике */
        }

        if (receivedCount.value >= expected) {
            stopPolling();
            return;
        }
        if (attempts >= MAX_ATTEMPTS) {
            // Очередь не успела за отведённые попытки — не зависаем, мягко уведомляем.
            stopPolling();
            toast.info(
                "Файлы ещё обрабатываются",
                "Обновите страницу через минуту, чтобы увидеть остальные.",
            );
            return;
        }
        pollTimer = setTimeout(tick, 1000);
    };
    pollTimer = setTimeout(tick, 1000);
}

function stopPolling() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
    uploading.value = false;
    phase.value = "uploading";
    pct.value = 0;
    expectedCount.value = 0;
    receivedCount.value = 0;
}
onBeforeUnmount(stopPolling);

/* ── Лайтбокс ─────────────────────────────────────────────────────────── */
const lbIndex = ref(-1);
function openItem(m) {
    if (m.type === "image") {
        lbIndex.value = images.value.findIndex((x) => x.id === m.id);
    } else if (m.url) {
        // Не-изображения открываем/скачиваем в новой вкладке.
        window.open(m.url, "_blank", "noopener");
    }
}

/* ── Превью с фолбэком на иконку при битой ссылке ─────────────────────── */
const broken = ref(new Set());
function onImgError(id) {
    const next = new Set(broken.value);
    next.add(id);
    broken.value = next;
}
function showThumb(m) {
    return m.type === "image" && !!m.thumb_url && !broken.value.has(m.id);
}

/* ── Выбор файлов (для массовых действий) ─────────────────────────────────
   Храним id выбранных в Set. Реактивность Set требует переприсваивания —
   тот же приём, что и у broken выше. Выбор переживает смену фильтра, поэтому
   «Выбрать все» оперирует только видимым подмножеством. */
const selected = ref(new Set());
function isSelected(id) {
    return selected.value.has(id);
}
function toggleSelect(id) {
    const next = new Set(selected.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected.value = next;
}
const selectedCount = computed(() => selected.value.size);
const allVisibleSelected = computed(
    () =>
        visible.value.length > 0 &&
        visible.value.every((m) => selected.value.has(m.id)),
);
// Partial selection — drives the checkbox's indeterminate ("mixed") state.
const someVisibleSelected = computed(
    () =>
        !allVisibleSelected.value &&
        visible.value.some((m) => selected.value.has(m.id)),
);
function toggleAllVisible() {
    const next = new Set(selected.value);
    if (allVisibleSelected.value) {
        for (const m of visible.value) next.delete(m.id);
    } else {
        for (const m of visible.value) next.add(m.id);
    }
    selected.value = next;
}
function clearSelection() {
    selected.value = new Set();
}
// Убрать ids из выбора (после удаления соответствующих строк).
function deselect(ids) {
    if (!selected.value.size) return;
    const drop = new Set(ids);
    const next = new Set([...selected.value].filter((id) => !drop.has(id)));
    selected.value = next;
}

/* ── Удаление одного файла ────────────────────────────────────────────── */
const delOpen = ref(false);
const delId = ref(null);
const delLoading = ref(false);
// Имя удаляемого файла для подписи в модалке (original_name → filename).
const delName = computed(() => {
    const m = rows.value.find((x) => x.id === delId.value);
    return m ? m.original_name || m.filename : "";
});
const delMessage = computed(() =>
    delName.value
        ? `Удалить файл «${delName.value}»? Действие необратимо.`
        : "Удалить этот файл? Действие необратимо.",
);
function askDelete(id) {
    delId.value = id;
    delOpen.value = true;
}
function confirmDelete() {
    const id = delId.value;
    delLoading.value = true;
    router.delete(`/admin/media/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            rows.value = rows.value.filter((m) => m.id !== id);
            deselect([id]);
        },
        onFinish: () => {
            delLoading.value = false;
            delOpen.value = false;
            delId.value = null;
        },
    });
}

/* ── Массовое удаление выбранных файлов ───────────────────────────────── */
const bulkOpen = ref(false);
const bulkLoading = ref(false);
const bulkMessage = computed(() => {
    const n = selectedCount.value;
    const word = pluralize(n, "файл", "файла", "файлов");
    return `Удалить выбранные файлы (${n} ${word})? Действие необратимо.`;
});
function askBulkDelete() {
    if (selectedCount.value) bulkOpen.value = true;
}
function confirmBulkDelete() {
    const ids = [...selected.value];
    if (!ids.length) {
        bulkOpen.value = false;
        return;
    }
    bulkLoading.value = true;
    router.delete("/admin/media/bulk", {
        data: { ids },
        preserveScroll: true,
        onSuccess: () => {
            const removed = new Set(ids);
            rows.value = rows.value.filter((m) => !removed.has(m.id));
            clearSelection();
        },
        onFinish: () => {
            bulkLoading.value = false;
            bulkOpen.value = false;
        },
    });
}

/* ── Пагинация ────────────────────────────────────────────────────────── */
function goPage(p) {
    router.get(
        "/admin/media",
        { page: p },
        { preserveState: false, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <AdminLayout title="Медиатека" subtitle="Файлы рабочего пространства">
        <div class="page">
            <NCard v-if="can('media.upload')">
                <NDropzone
                    title="Перетащите файлы сюда"
                    or-label="или"
                    browse-label="выберите на устройстве"
                    hint="изображения, видео, аудио, документы · до 50 МБ"
                    @files="upload"
                />
                <div v-if="uploading" class="upload-progress">
                    <!-- Фаза 1: реальная заливка байтов (детерминантный бар). -->
                    <NProgress
                        v-if="phase === 'uploading'"
                        :value="pct"
                        label="Загрузка"
                        show-value
                    />
                    <!-- Фаза 2: очередь генерирует превью — индетерминантный статус. -->
                    <div
                        v-else
                        class="upload-processing"
                        role="status"
                        aria-live="polite"
                    >
                        <NSpinner :size="18" :width="2" />
                        <span class="upload-processing__label">{{
                            processingLabel
                        }}</span>
                    </div>
                </div>
            </NCard>

            <!-- Панель управления: выбор всех + фильтр по типу + переключатель вида -->
            <div v-if="rows.length" class="toolbar">
                <div class="toolbar__group">
                    <NCheckbox
                        v-if="can('media.delete') && visible.length"
                        :model-value="allVisibleSelected"
                        :indeterminate="someVisibleSelected"
                        @update:model-value="toggleAllVisible"
                    >
                        Выбрать все
                    </NCheckbox>
                    <NSegmented
                        v-model="filter"
                        :options="filterOpts"
                        aria-label="Фильтр по типу"
                    />
                </div>
                <NSegmented
                    v-model="view"
                    :options="viewOpts"
                    aria-label="Вид"
                />
            </div>

            <!-- Панель массовых действий: появляется, когда что-то выбрано -->
            <div
                v-if="can('media.delete') && selectedCount"
                class="selbar"
                role="region"
                aria-label="Массовые действия"
            >
                <span class="selbar__count">
                    Выбрано: {{ selectedCount }}
                    {{ pluralize(selectedCount, "файл", "файла", "файлов") }}
                </span>
                <div class="selbar__actions">
                    <NButton variant="ghost" size="sm" @click="clearSelection">
                        Снять выделение
                    </NButton>
                    <NButton
                        variant="danger"
                        size="sm"
                        icon="trash"
                        @click="askBulkDelete"
                    >
                        Удалить выбранные
                    </NButton>
                </div>
            </div>

            <!-- Сетка карточек -->
            <div
                v-if="visible.length"
                class="media"
                :class="view === 'list' ? 'media--list' : 'media--grid'"
            >
                <div
                    v-for="m in visible"
                    :key="m.id"
                    class="mcard"
                    :class="{ 'mcard--selected': isSelected(m.id) }"
                >
                    <div class="mcard__preview">
                        <button
                            type="button"
                            class="mcard__open"
                            :class="{ 'mcard__open--clickable': m.url }"
                            :disabled="!m.url"
                            :aria-label="
                                (m.type === 'image'
                                    ? 'Открыть изображение: '
                                    : 'Открыть файл в новой вкладке: ') +
                                (m.original_name || m.filename)
                            "
                            @click="openItem(m)"
                        >
                            <img
                                v-if="showThumb(m)"
                                class="mcard__img"
                                :src="m.thumb_url"
                                :alt="m.original_name || m.filename"
                                loading="lazy"
                                @error="onImgError(m.id)"
                            />
                            <span v-else class="mcard__glyph">
                                <NIcon :name="typeIcon(m)" :size="34" />
                            </span>
                        </button>

                        <!-- Чекбокс выбора: виден при наведении и пока карточка выбрана -->
                        <span
                            v-if="can('media.delete')"
                            class="mcard__check"
                            :class="{ 'mcard__check--on': isSelected(m.id) }"
                            @click.stop
                        >
                            <NCheckbox
                                :model-value="isSelected(m.id)"
                                :aria-label="
                                    'Выбрать: ' +
                                    (m.original_name || m.filename)
                                "
                                @update:model-value="toggleSelect(m.id)"
                            />
                        </span>

                        <span class="mcard__badge">{{ typeBadge(m) }}</span>
                        <NButton
                            v-if="can('media.delete')"
                            class="mcard__del"
                            variant="ghost"
                            tone="danger"
                            size="sm"
                            icon="trash"
                            :aria-label="
                                'Удалить: ' + (m.original_name || m.filename)
                            "
                            @click.stop="askDelete(m.id)"
                        />
                    </div>
                    <div class="mcard__foot">
                        <div class="mcard__name">
                            {{ m.original_name || m.filename }}
                        </div>
                        <div class="mcard__meta">
                            <span>{{ formatBytes(m.size) }}</span>
                            <span>{{ typeLabel(m) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <NEmptyState
                v-else-if="!rows.length"
                icon="asset"
                title="Пока пусто"
                description="Загрузите первые файлы выше — изображения, видео, аудио или документы."
            />
            <NEmptyState
                v-else
                icon="filter"
                title="Ничего не найдено"
                description="Нет файлов выбранного типа. Измените фильтр."
            />

            <div v-if="media.last_page > 1" class="page__pager">
                <NPagination
                    :page="media.current_page"
                    :pages="media.last_page"
                    prev-label="Назад"
                    next-label="Вперёд"
                    aria-label="Навигация по страницам"
                    @update:page="goPage"
                />
            </div>
        </div>

        <NLightbox
            :items="lbItems"
            v-model:index="lbIndex"
            dialog-label="Просмотр изображения"
            close-label="Закрыть"
            prev-label="Предыдущее фото"
            next-label="Следующее фото"
        />

        <ConfirmModal
            :open="delOpen"
            title="Удалить медиа"
            :message="delMessage"
            :loading="delLoading"
            @confirm="confirmDelete"
            @cancel="delOpen = false"
            @update:open="delOpen = $event"
        />

        <ConfirmModal
            :open="bulkOpen"
            title="Удалить медиа"
            :message="bulkMessage"
            :loading="bulkLoading"
            @confirm="confirmBulkDelete"
            @cancel="bulkOpen = false"
            @update:open="bulkOpen = $event"
        />
    </AdminLayout>
</template>

<style scoped>
/* .page / .page__pager — общие утилиты в resources/js/admin/styles.css */
.upload-progress {
    margin-top: 16px;
}
/* Фаза «Обработка…» — индетерминантный статус со спиннером (NProgress
   умеет только детерминантный бар, поэтому показываем спиннер + подпись). */
.upload-processing {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-2);
    font-size: 12.5px;
    font-weight: 600;
}
.upload-processing__label {
    color: var(--text-2);
}
.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.toolbar__group {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

/* ── Панель массовых действий ─────────────────────────────────────────── */
.selbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 10px 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}
.selbar__count {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-2);
}
.selbar__actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* ── Сетка ────────────────────────────────────────────────────────────── */
.media--grid {
    display: grid;
    /* Минимальная ширина колонки слегка масштабируется плотностью:
       базовая 200px + поправка от --fs (12.5 / 14 / 16px). */
    grid-template-columns: repeat(
        auto-fill,
        minmax(calc(186px + var(--fs)), 1fr)
    );
    gap: 16px;
}
.media--list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* ── Карточка ─────────────────────────────────────────────────────────── */
.mcard {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition:
        box-shadow 0.16s ease,
        transform 0.16s ease,
        border-color 0.16s ease;
}
.mcard:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
/* Выбранная карточка — акцентная рамка-кольцо без сдвига вёрстки. */
.mcard--selected {
    border-color: var(--accent);
    box-shadow:
        0 0 0 1px var(--accent),
        var(--shadow-md);
}
.mcard__preview {
    position: relative;
    aspect-ratio: 16 / 10;
    background: var(--surface-3);
    background-image: repeating-linear-gradient(
        135deg,
        var(--border) 0 1px,
        transparent 1px 13px
    );
    display: flex;
    align-items: center;
    justify-content: center;
}
/* Кнопка открытия — заполняет всё превью; оверлеи (выбор/бейдж/удаление)
   лежат поверх неё с z-index. */
.mcard__open {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    background: transparent;
    padding: 0;
    cursor: default;
}
.mcard__open--clickable {
    cursor: pointer;
}
.mcard__open:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: -2px;
}
.mcard__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.mcard__glyph {
    color: var(--text-3);
    display: flex;
}
/* Чекбокс выбора — верхний левый угол превью. Скрыт, проявляется при
   наведении на карточку или пока файл выбран; не перехватывает клик-открытие. */
.mcard__check {
    position: absolute;
    top: 9px;
    left: 9px;
    z-index: 1;
    display: flex;
    opacity: 0;
    transition: opacity 0.14s ease;
}
.mcard:hover .mcard__check,
.mcard__check--on {
    opacity: 1;
}
/* Touch devices have no hover — keep the per-file checkbox visible (WCAG/mobile). */
@media (hover: none) {
    .mcard__check {
        opacity: 1;
    }
}
.mcard__badge {
    position: absolute;
    bottom: 9px;
    left: 9px;
    z-index: 1;
    font-size: 10.5px;
    font-weight: 700;
    background: var(--surface);
    color: var(--text-2);
    padding: 2px 7px;
    border-radius: 6px;
    border: 1px solid var(--border);
    font-family: var(--font-mono);
}
.mcard__del {
    position: absolute;
    top: 9px;
    right: 9px;
    z-index: 1;
    width: 26px;
    height: 26px;
    background: var(--surface);
    box-shadow: var(--shadow-sm);
}
.mcard__foot {
    /* Плотность (S/M/L) управляет паддингом строки и базовым кеглем. */
    padding: var(--row-pad) calc(var(--row-pad) - 1px);
    transition: padding 0.18s ease;
}
.mcard__name {
    font-weight: 700;
    font-size: var(--fs);
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mcard__meta {
    /* Чуть мельче имени, тоже завязано на плотность. */
    font-size: calc(var(--fs) - 2px);
    color: var(--text-3);
    display: flex;
    justify-content: space-between;
    margin-top: 4px;
}

/* ── Список ───────────────────────────────────────────────────────────── */
.media--list .mcard {
    display: flex;
    align-items: stretch;
}
.media--list .mcard:hover {
    transform: none;
}
.media--list .mcard__preview {
    /* Ширина превью строки масштабируется плотностью (S/M/L),
       как и паддинг/кегль ниже — иначе строка визуально не меняется. */
    width: calc(var(--row-h) * 2.4);
    flex: none;
    aspect-ratio: auto;
    transition: width 0.18s ease;
}
.media--list .mcard__foot {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 4px;
}
.media--list .mcard__meta {
    justify-content: flex-start;
    gap: 14px;
}
</style>
