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
    // Inertia paginator. Each row: { id, filename, original_name, mime_type,
    // type ('image'|'video'|'audio'|'document'|'other'), size (bytes), url, thumb_url, created_at }.
    media: { type: Object, required: true },
});

const toast = useToast();

/* ── Local row list ──────────────────────────────────────────────────────
   Starts from the server page; media fetched by polling after upload are
   prepended to it, and deleted items are removed from it. */
const rows = ref([...props.media.data]);

/* ── Labels and icons by type ─────────────────────────────────────────── */
const TYPE_LABEL = {
    image: "Фото",
    video: "Видео",
    audio: "Аудио",
    document: "Документ",
    other: "Файл",
};
// Icons are limited to the NIcon set — we pick the closest meaningful ones.
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
// Badge label like JPG / MP4 / PDF — from the name extension, otherwise from the MIME subtype.
function typeBadge(m) {
    const name = m.original_name || m.filename || "";
    const ext = name.includes(".") ? name.split(".").pop() : "";
    if (ext) return ext.toUpperCase().slice(0, 5);
    const sub = (m.mime_type || "").split("/")[1] || m.type || "";
    return sub.toUpperCase().slice(0, 5);
}

/* ── Type filter + view toggle ────────────────────────────────────────── */
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
// The lightbox works only with images — indices are computed within their subset.
const images = computed(() => visible.value.filter((m) => m.type === "image"));
// Neutral item shape for NLightbox: { url, caption }.
const lbItems = computed(() =>
    images.value.map((m) => ({
        url: m.url,
        caption: m.original_name || m.filename,
    })),
);

/* ── Upload: two phases — "Uploading" (real XHR bytes) → "Processing"
   (indeterminate indicator while polling pulls the files processed by the
   queue). Small files upload instantly, the real work (thumbnail generation)
   runs in the queue — so we no longer show a static 100%. */
const uploading = ref(false);
const phase = ref("uploading"); // 'uploading' | 'processing'
const pct = ref(0);
const expectedCount = ref(0); // how many files were queued
const receivedCount = ref(0); // how many have already been pulled by polling
let pollTimer = null;

// How many files are still processing (for the label), but never below zero.
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
    // Cancel the previous upload's polling, otherwise a quick repeat drop leaves
    // an orphaned timer whose counters get clobbered by the new batch.
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
        // onload fires for any completed response — 4xx/5xx land here too.
        if (xhr.status < 200 || xhr.status >= 300) {
            uploadFailed(xhr);
            return;
        }
        // Bytes delivered — the real work is now in the queue.
        pct.value = 100;
        let queued = files.length;
        try {
            const json = JSON.parse(xhr.responseText || "{}");
            if (typeof json.queued === "number") queued = json.queued;
        } catch {
            /* no JSON — use the number of selected files as the expectation */
        }
        // Move to the second phase: the indeterminate "Processing…".
        phase.value = "processing";
        startPolling(queued);
    };
    xhr.onerror = () => uploadFailed();
    xhr.send(fd);
}

// Report an upload error and reset the indicator. The text comes from the server's
// JSON response (validation/limit), otherwise a generic message.
function uploadFailed(xhr) {
    let msg = "Проверьте размер и формат файлов и попробуйте снова.";
    try {
        const json = JSON.parse(xhr?.responseText || "{}");
        if (json.message) msg = json.message;
    } catch {
        /* no JSON — keep the generic message */
    }
    stopPolling();
    toast.error("Не удалось загрузить файлы", msg);
}

// Poll /poll until all expected media are pulled in
// or until we exhaust the attempt limit (the queue may have rejected a file).
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
                // poll returns by descending id — we prepend them in ascending order.
                const known = new Set(rows.value.map((m) => m.id));
                const add = fresh.filter((m) => !known.has(m.id));
                for (const m of [...add].reverse()) rows.value.unshift(m);
                receivedCount.value += add.length;
            }
        } catch {
            /* transient network error — we'll retry on the next tick */
        }

        if (receivedCount.value >= expected) {
            stopPolling();
            return;
        }
        if (attempts >= MAX_ATTEMPTS) {
            // The queue didn't finish within the allotted attempts — don't hang, notify gently.
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

/* ── Lightbox ─────────────────────────────────────────────────────────── */
const lbIndex = ref(-1);
function openItem(m) {
    if (m.type === "image") {
        lbIndex.value = images.value.findIndex((x) => x.id === m.id);
    } else if (m.url) {
        // Non-images are opened/downloaded in a new tab.
        window.open(m.url, "_blank", "noopener");
    }
}

/* ── Thumbnail with an icon fallback on a broken link ─────────────────── */
const broken = ref(new Set());
function onImgError(id) {
    const next = new Set(broken.value);
    next.add(id);
    broken.value = next;
}
function showThumb(m) {
    return m.type === "image" && !!m.thumb_url && !broken.value.has(m.id);
}

/* ── File selection (for bulk actions) ────────────────────────────────────
   We keep the selected ids in a Set. Set reactivity requires reassignment —
   the same trick as broken above. The selection survives a filter change, so
   "Select all" operates only on the visible subset. */
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
// Remove ids from the selection (after deleting the corresponding rows).
function deselect(ids) {
    if (!selected.value.size) return;
    const drop = new Set(ids);
    const next = new Set([...selected.value].filter((id) => !drop.has(id)));
    selected.value = next;
}

/* ── Deleting a single file ───────────────────────────────────────────── */
const delOpen = ref(false);
const delId = ref(null);
const delLoading = ref(false);
// Name of the file being deleted, for the modal label (original_name → filename).
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

/* ── Bulk deletion of selected files ──────────────────────────────────── */
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

/* ── Pagination ───────────────────────────────────────────────────────── */
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
                    <!-- Phase 1: real byte upload (determinate bar). -->
                    <NProgress
                        v-if="phase === 'uploading'"
                        :value="pct"
                        label="Загрузка"
                        show-value
                    />
                    <!-- Phase 2: the queue generates thumbnails — indeterminate status. -->
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

            <!-- Control bar: select all + type filter + view toggle -->
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

            <!-- Bulk actions bar: appears when something is selected -->
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

            <!-- Card grid -->
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

                        <!-- Selection checkbox: visible on hover and while the card is selected -->
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
/* .page / .page__pager — shared utilities in resources/js/admin/styles.css */
.upload-progress {
    margin-top: 16px;
}
/* "Processing…" phase — an indeterminate status with a spinner (NProgress
   only does a determinate bar, so we show a spinner + label). */
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

/* ── Bulk actions bar ─────────────────────────────────────────────────── */
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

/* ── Grid ─────────────────────────────────────────────────────────────── */
.media--grid {
    display: grid;
    /* The minimum column width scales slightly with density:
       a 200px base + a correction from --fs (12.5 / 14 / 16px). */
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

/* ── Card ─────────────────────────────────────────────────────────────── */
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
/* Selected card — an accent ring border without shifting the layout. */
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
/* Open button — fills the entire preview; overlays (selection/badge/delete)
   sit on top of it via z-index. */
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
/* Selection checkbox — top-left corner of the preview. Hidden, revealed on
   card hover or while the file is selected; doesn't intercept the open click. */
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
    /* Density (S/M/L) controls the row padding and base font size. */
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
    /* Slightly smaller than the name, also tied to density. */
    font-size: calc(var(--fs) - 2px);
    color: var(--text-3);
    display: flex;
    justify-content: space-between;
    margin-top: 4px;
}

/* ── List ─────────────────────────────────────────────────────────────── */
.media--list .mcard {
    display: flex;
    align-items: stretch;
}
.media--list .mcard:hover {
    transform: none;
}
.media--list .mcard__preview {
    /* The row preview width scales with density (S/M/L),
       like the padding/font size below — otherwise the row doesn't change visually. */
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
