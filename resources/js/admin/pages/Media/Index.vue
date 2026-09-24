<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from "vue";
import type { PropType } from "vue";
import type { MediaDetails, MediaItem, Pagination } from "@/admin/types";
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
    NModal,
    NInput,
    NFormField,
    NSelect,
    NDrawer,
    useToast,
} from "nergous-ui-vue";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
import { can } from "@/lib/can";
import { formatBytes, formatDateTime, pluralize } from "@/lib/format";

const props = defineProps({
    media: { type: Object as PropType<Pagination<MediaItem>>, required: true },
    currentSort: { type: String, default: "created_at" },
    currentDirection: { type: String, default: "desc" },
    perPage: { type: Number, default: 24 },
    perPageOptions: {
        type: Array as PropType<number[]>,
        default: () => [24, 48, 96],
    },
    filters: {
        type: Object as PropType<{ search?: string; type?: string }>,
        default: () => ({}),
    },
});

const toast = useToast();

// Keep polled uploads and local deletions alongside the server's page.
const rows = ref([...props.media.data]);
// A filter, sort or page change brings a new server page: replace the rows and
// drop the selection, so bulk delete never touches files the user can't see.
watch(
    () => props.media,
    (media) => {
        rows.value = [...media.data];
        selected.value = new Set();
    },
);

const TYPE_LABEL: Record<string, string> = {
    image: "Фото",
    video: "Видео",
    audio: "Аудио",
    document: "Документ",
    other: "Файл",
};
// Use icons available in the design system.
const TYPE_ICON: Record<string, string> = {
    image: "asset",
    video: "layers",
    audio: "activity",
    document: "copy",
    other: "asset",
};
function typeLabel(m: MediaItem) {
    return TYPE_LABEL[m.type] || TYPE_LABEL.other;
}
function typeIcon(m: MediaItem) {
    return TYPE_ICON[m.type] || TYPE_ICON.other;
}
// Prefer the filename extension for short file-type badges.
function typeBadge(m: MediaItem) {
    const name = m.original_name || m.filename || "";
    const ext = name.includes(".") ? name.split(".").pop() : "";
    if (ext) return ext.toUpperCase().slice(0, 5);
    const sub = (m.mime_type || "").split("/")[1] || m.type || "";
    return sub.toUpperCase().slice(0, 5);
}

const filter = ref(props.filters.type || "all");
const search = ref(props.filters.search ?? "");
const sortKey = ref(`${props.currentSort}:${props.currentDirection}`);
const sortOpts = [
    { value: "created_at:desc", label: "Сначала новые" },
    { value: "created_at:asc", label: "Сначала старые" },
    { value: "original_name:asc", label: "По имени, А → Я" },
    { value: "original_name:desc", label: "По имени, Я → А" },
];

const { reload, onSearch } = useIndexFilters("/admin/media", () => {
    const [sort, direction] = sortKey.value.split(":");
    return {
        search: search.value || undefined,
        type: filter.value === "all" ? undefined : filter.value,
        sort,
        direction,
        per_page: props.perPage,
    };
});
const hasFilters = computed(
    () => filter.value !== "all" || search.value.trim() !== "",
);
const showPager = computed(
    () =>
        props.media.last_page > 1 ||
        props.media.total > Math.min(...props.perPageOptions),
);
const filterOpts = [
    { value: "all", label: "Все" },
    { value: "image", label: "Фото" },
    { value: "video", label: "Видео" },
    { value: "audio", label: "Аудио" },
    { value: "document", label: "Документы" },
    { value: "other", label: "Другое" },
];
const view = ref("grid");
const viewOpts = [
    { value: "grid", icon: "grid", label: "Сетка" },
    { value: "list", icon: "list", label: "Список" },
];

// The server already filters by type; polled uploads are filtered here too.
const visible = computed(() =>
    filter.value === "all"
        ? rows.value
        : rows.value.filter((m) => m.type === filter.value),
);
// Lightbox indices refer to images only.
const images = computed(() => visible.value.filter((m) => m.type === "image"));
const lbItems = computed(() =>
    images.value.map((m) => ({
        url: m.url,
        caption: m.original_name || m.filename,
    })),
);

// Upload progress covers transfer; polling tracks subsequent queue processing.
const uploading = ref(false);
const phase = ref("uploading"); // 'uploading' | 'processing'
const pct = ref(0);
const expectedCount = ref(0);
const receivedCount = ref(0);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

// The queue count can lag behind received files; never show a negative value.
const processingLeft = computed(() =>
    Math.max(0, expectedCount.value - receivedCount.value),
);
const processingLabel = computed(() => {
    const n = processingLeft.value;
    if (n <= 0) return "Обработка файлов…";
    return `Обработка ${n} ${pluralize(n, "файла", "файлов", "файлов")}…`;
});

function upload(files: File[]) {
    if (!files?.length) return;
    // Cancel the previous upload's polling, otherwise a quick repeat drop leaves
    // an orphaned timer whose counters get clobbered by the new batch.
    stopPolling();
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
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
        // onload also fires for 4xx and 5xx responses.
        if (xhr.status < 200 || xhr.status >= 300) {
            uploadFailed(xhr);
            return;
        }
        // Bytes delivered — the real work is now in the queue.
        pct.value = 100;
        let queued = files.length;
        let afterId = 0;
        try {
            const json = JSON.parse(xhr.responseText || "{}");
            if (typeof json.queued === "number") queued = json.queued;
            if (typeof json.after_id === "number") afterId = json.after_id;
        } catch {
            // Fall back to the selected-file count when the response has no JSON.
        }
        // The queue may continue after the upload reaches 100%.
        phase.value = "processing";
        startPolling(queued, afterId);
    };
    xhr.onerror = () => uploadFailed();
    xhr.send(fd);
}

// Report an upload error and reset the indicator. The text comes from the server's
// JSON response (validation/limit), otherwise a generic message.
function uploadFailed(xhr?: XMLHttpRequest) {
    let msg = "Проверьте размер и формат файлов и попробуйте снова.";
    try {
        const json = JSON.parse(xhr?.responseText || "{}");
        if (json.message) msg = json.message;
    } catch {
        // Keep the generic message if the response has no JSON.
    }
    stopPolling();
    toast.error("Не удалось загрузить файлы", msg);
}

// Stop polling when all queued files arrive or the attempt limit is reached.
// afterId is the largest id before the upload (from the server), so rows that
// are not on the current page or were filtered out are never counted twice.
function startPolling(expected: number, afterId: number) {
    expectedCount.value = expected;
    receivedCount.value = 0;
    let attempts = 0;
    const MAX_ATTEMPTS = 20;

    const tick = async () => {
        attempts += 1;
        try {
            const res = await fetch(`/admin/media/poll?after_id=${afterId}`, {
                headers: { Accept: "application/json" },
            });
            if (!res.ok) throw new Error(String(res.status));
            const fresh = (await res.json()) as MediaItem[];
            if (Array.isArray(fresh)) {
                // Poll results arrive newest first; prepend them in ascending order.
                const known = new Set(rows.value.map((m) => m.id));
                const add = fresh.filter((m) => !known.has(m.id));
                for (const m of [...add].reverse()) rows.value.unshift(m);
                receivedCount.value = fresh.length;
            }
        } catch {
            // Retry transient network errors on the next tick.
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

const lbIndex = ref(-1);
function openItem(m: MediaItem) {
    if (m.type === "image") {
        lbIndex.value = images.value.findIndex((x) => x.id === m.id);
    } else if (m.url) {
        // Non-images are opened/downloaded in a new tab.
        window.open(m.url, "_blank", "noopener");
    }
}

const broken = ref(new Set<number>());
function onImgError(id: number) {
    const next = new Set(broken.value);
    next.add(id);
    broken.value = next;
}
function showThumb(m: MediaItem) {
    return m.type === "image" && !!m.thumb_url && !broken.value.has(m.id);
}

// Keep selected IDs across filters; reassign the Set to trigger Vue updates.
const selected = ref(new Set<number>());
function isSelected(id: number) {
    return selected.value.has(id);
}
function toggleSelect(id: number) {
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
function deselect(ids: number[]) {
    if (!selected.value.size) return;
    const drop = new Set(ids);
    const next = new Set([...selected.value].filter((id) => !drop.has(id)));
    selected.value = next;
}

const renOpen = ref(false);
const renId = ref<number | null>(null);
const renName = ref("");
const renError = ref("");
const renLoading = ref(false);

function askRename(m: MediaItem) {
    renId.value = m.id;
    renName.value = m.original_name || m.filename.split("/").pop() || "";
    renError.value = "";
    renOpen.value = true;
}
function confirmRename() {
    const id = renId.value;
    const name = renName.value.trim();
    if (!name) {
        renError.value = "Введите имя файла";
        return;
    }
    renLoading.value = true;
    router.patch(
        `/admin/media/${id}`,
        { original_name: name },
        {
            preserveScroll: true,
            onSuccess: () => {
                // The server redirects back; the local row is updated in place so
                // polled-in rows (absent from the server page props) don't get lost.
                const row = rows.value.find((m) => m.id === id);
                if (row) row.original_name = name;
                renOpen.value = false;
            },
            onError: (errors) => {
                renError.value =
                    errors.original_name || "Не удалось переименовать файл";
            },
            onFinish: () => {
                renLoading.value = false;
            },
        },
    );
}

const delOpen = ref(false);
const delId = ref<number | null>(null);
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
function askDelete(id: number) {
    delId.value = id;
    delOpen.value = true;
}
function confirmDelete() {
    const id = delId.value;
    if (id === null) return;
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

function fileName(m: MediaItem) {
    return m.original_name || m.filename;
}

function absoluteUrl(url: string) {
    return new URL(url, window.location.origin).toString();
}

async function copyUrl(m: MediaItem) {
    try {
        await navigator.clipboard.writeText(absoluteUrl(m.url));
        toast.success("Ссылка скопирована", fileName(m));
    } catch {
        toast.error(
            "Не удалось скопировать",
            "Браузер запретил доступ к буферу обмена.",
        );
    }
}

// File details drawer (GET /admin/media/{id}: dimensions, uploader, dates).
const infoOpen = ref(false);
const info = ref<MediaDetails | null>(null);
const infoLoading = ref(false);

async function fetchDetails(id: number): Promise<MediaDetails> {
    const res = await fetch(`/admin/media/${id}`, {
        headers: { Accept: "application/json" },
    });
    if (!res.ok) throw new Error(String(res.status));
    return (await res.json()) as MediaDetails;
}

async function openInfo(m: MediaItem) {
    info.value = {
        ...m,
        dimensions: null,
        uploaded_by: null,
        updated_by: null,
        updated_at: null,
    };
    infoOpen.value = true;
    infoLoading.value = true;
    try {
        info.value = await fetchDetails(m.id);
    } catch {
        toast.error("Не удалось загрузить сведения о файле");
    } finally {
        infoLoading.value = false;
    }
}

// Replacing the file keeps the record (id, name, attachments); the queue stores
// the new file under a new path, which the poll below waits for.
const replaceInput = ref<HTMLInputElement | null>(null);
const replacing = ref(false);

function pickReplacement() {
    replaceInput.value?.click();
}

function applyDetails(details: MediaDetails) {
    const row = rows.value.find((m) => m.id === details.id);
    if (row) {
        Object.assign(row, {
            filename: details.filename.split("/").pop() || details.filename,
            mime_type: details.mime_type,
            type: details.type,
            size: details.size,
            url: details.url,
            thumb_url: details.thumb_url,
        });
        const nextBroken = new Set(broken.value);
        nextBroken.delete(details.id);
        broken.value = nextBroken;
    }
    if (info.value?.id === details.id) info.value = details;
}

function onReplaceFile(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = "";
    const target = info.value;
    if (!file || !target) return;

    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const fd = new FormData();
    fd.append("file", file);
    replacing.value = true;

    fetch(`/admin/media/${target.id}/replace`, {
        method: "POST",
        headers: { Accept: "application/json", "X-CSRF-TOKEN": token || "" },
        body: fd,
    })
        .then(async (res) => {
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(
                    json.errors?.file?.[0] || json.message || "Ошибка загрузки",
                );
            }
            waitForReplacement(target.id, String(json.filename ?? ""));
        })
        .catch((err: Error) => {
            replacing.value = false;
            toast.error("Не удалось заменить файл", err.message);
        });
}

function waitForReplacement(id: number, oldFilename: string, attempt = 0) {
    setTimeout(async () => {
        try {
            const details = await fetchDetails(id);
            if (details.filename !== oldFilename) {
                applyDetails(details);
                replacing.value = false;
                toast.success("Файл заменён", fileName(details));
                return;
            }
        } catch {
            // Retry transient errors on the next attempt.
        }
        if (attempt >= 20) {
            replacing.value = false;
            toast.info(
                "Файл ещё обрабатывается",
                "Обновите страницу через минуту.",
            );
            return;
        }
        waitForReplacement(id, oldFilename, attempt + 1);
    }, 1500);
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
                    <NProgress
                        v-if="phase === 'uploading'"
                        :value="pct"
                        label="Загрузка"
                        show-value
                    />
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

            <div v-if="rows.length || hasFilters" class="toolbar">
                <div class="toolbar__group">
                    <NInput
                        v-model="search"
                        class="toolbar__search"
                        icon="search"
                        placeholder="Поиск по имени файла…"
                        aria-label="Поиск по имени файла"
                        @update:model-value="onSearch"
                    />
                    <NSegmented
                        v-model="filter"
                        :options="filterOpts"
                        aria-label="Фильтр по типу"
                        @update:model-value="reload({ page: 1 })"
                    />
                </div>
                <div class="toolbar__group">
                    <NSelect
                        v-model="sortKey"
                        :options="sortOpts"
                        aria-label="Сортировка"
                        class="toolbar__sort"
                        @update:model-value="reload({ page: 1 })"
                    />
                    <NSegmented
                        v-model="view"
                        :options="viewOpts"
                        aria-label="Вид"
                    />
                </div>
            </div>

            <div v-if="can('media.delete') && visible.length" class="subbar">
                <NCheckbox
                    :model-value="allVisibleSelected"
                    :indeterminate="someVisibleSelected"
                    @update:model-value="toggleAllVisible"
                >
                    Выбрать все на странице
                </NCheckbox>
                <span class="subbar__total"
                    >Всего: {{ media.total }}
                    {{
                        pluralize(media.total, "файл", "файла", "файлов")
                    }}</span
                >
            </div>

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
                                :src="m.thumb_url || undefined"
                                :alt="m.original_name || m.filename"
                                loading="lazy"
                                @error="onImgError(m.id)"
                            />
                            <span v-else class="mcard__glyph">
                                <NIcon :name="typeIcon(m)" :size="34" />
                            </span>
                        </button>

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
                        <div class="mcard__actions">
                            <NButton
                                class="mcard__act"
                                variant="ghost"
                                tone="accent"
                                size="sm"
                                icon="eye"
                                :aria-label="'Сведения: ' + fileName(m)"
                                title="Сведения о файле"
                                @click.stop="openInfo(m)"
                            />
                            <NButton
                                class="mcard__act"
                                variant="ghost"
                                tone="accent"
                                size="sm"
                                icon="link"
                                :aria-label="
                                    'Скопировать ссылку: ' + fileName(m)
                                "
                                title="Скопировать ссылку"
                                @click.stop="copyUrl(m)"
                            />
                            <NButton
                                v-if="can('media.edit')"
                                class="mcard__act"
                                variant="ghost"
                                tone="accent"
                                size="sm"
                                icon="edit"
                                :aria-label="
                                    'Переименовать: ' +
                                    (m.original_name || m.filename)
                                "
                                @click.stop="askRename(m)"
                            />
                            <NButton
                                v-if="can('media.delete')"
                                class="mcard__act"
                                variant="ghost"
                                tone="danger"
                                size="sm"
                                icon="trash"
                                :aria-label="
                                    'Удалить: ' +
                                    (m.original_name || m.filename)
                                "
                                @click.stop="askDelete(m.id)"
                            />
                        </div>
                    </div>
                    <div class="mcard__foot">
                        <div class="mcard__name">
                            {{ m.original_name || m.filename }}
                        </div>
                        <div class="mcard__meta">
                            <span>{{ formatBytes(m.size) }}</span>
                            <span>{{ typeLabel(m) }}</span>
                        </div>
                        <div class="mcard__date">
                            {{
                                m.created_local || formatDateTime(m.created_at)
                            }}
                        </div>
                    </div>
                </div>
            </div>

            <NEmptyState
                v-else-if="!hasFilters"
                icon="asset"
                title="Пока пусто"
                description="Загрузите первые файлы выше — изображения, видео, аудио или документы."
            />
            <NEmptyState
                v-else
                icon="filter"
                title="Ничего не найдено"
                description="Нет файлов по выбранному фильтру или запросу."
            />

            <div v-if="showPager" class="page__pager">
                <NPagination
                    :page="media.current_page"
                    :pages="media.last_page"
                    :page-size="perPage"
                    :page-sizes="perPageOptions"
                    page-size-label="Показывать по"
                    jumpable
                    prev-label="Назад"
                    next-label="Вперёд"
                    jump-label="Страница"
                    jump-button-label="Перейти"
                    total-label="из"
                    jump-error-label="Введите корректный номер страницы"
                    aria-label="Навигация по страницам"
                    @update:page="(p) => reload({ page: p })"
                    @update:page-size="
                        (size) => reload({ per_page: size, page: 1 })
                    "
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

        <NModal
            v-model="renOpen"
            title="Переименовать файл"
            width="420px"
            close-label="Закрыть"
        >
            <NFormField
                label="Имя файла"
                :error="renError"
                hint="Меняется только отображаемое имя, ссылки на файл не изменятся."
                required
            >
                <NInput
                    v-model="renName"
                    :error="!!renError"
                    placeholder="Название файла"
                    @keyup.enter="confirmRename"
                />
            </NFormField>
            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton
                    variant="primary"
                    block
                    :loading="renLoading"
                    @click="confirmRename"
                    >Сохранить</NButton
                >
            </template>
        </NModal>

        <NDrawer
            v-model="infoOpen"
            title="Сведения о файле"
            :subtitle="info ? fileName(info) : ''"
            close-label="Закрыть"
        >
            <div v-if="info" class="info">
                <div class="info__preview">
                    <img
                        v-if="info.type === 'image' && info.thumb_url"
                        :src="info.thumb_url"
                        :alt="fileName(info)"
                    />
                    <NIcon v-else :name="typeIcon(info)" :size="40" />
                    <span v-if="infoLoading || replacing" class="info__busy">
                        <NSpinner :size="20" :width="2" />
                    </span>
                </div>
                <dl class="info__list">
                    <dt>Имя</dt>
                    <dd>{{ fileName(info) }}</dd>
                    <dt>Тип</dt>
                    <dd>{{ typeLabel(info) }} · {{ info.mime_type || "—" }}</dd>
                    <dt>Размер</dt>
                    <dd>{{ formatBytes(info.size) }}</dd>
                    <template v-if="info.dimensions">
                        <dt>Разрешение</dt>
                        <dd>
                            {{ info.dimensions.width }} ×
                            {{ info.dimensions.height }} px
                        </dd>
                    </template>
                    <dt>Загрузил</dt>
                    <dd>{{ info.uploaded_by || "—" }}</dd>
                    <dt>Загружен</dt>
                    <dd>
                        {{
                            info.created_local ||
                            formatDateTime(info.created_at)
                        }}
                    </dd>
                    <template v-if="info.updated_by">
                        <dt>Изменил</dt>
                        <dd>
                            {{ info.updated_by }} ·
                            {{ formatDateTime(info.updated_at) }}
                        </dd>
                    </template>
                    <dt>Ссылка</dt>
                    <dd class="info__url">{{ absoluteUrl(info.url) }}</dd>
                </dl>
                <div class="info__actions">
                    <NButton
                        variant="secondary"
                        icon="link"
                        @click="copyUrl(info)"
                        >Скопировать ссылку</NButton
                    >
                    <NButton
                        variant="secondary"
                        icon="ext"
                        :as="'a'"
                        :href="info.url"
                        target="_blank"
                        rel="noopener"
                        >Открыть</NButton
                    >
                    <NButton
                        v-if="can('media.edit')"
                        variant="secondary"
                        icon="upload"
                        :loading="replacing"
                        @click="pickReplacement"
                        >Заменить файл</NButton
                    >
                </div>
                <p v-if="can('media.edit')" class="info__hint">
                    При замене запись и имя сохраняются, но адрес файла меняется
                    — внешние ссылки на старый адрес перестанут работать.
                </p>
                <input
                    ref="replaceInput"
                    type="file"
                    class="info__file"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="onReplaceFile"
                />
            </div>
        </NDrawer>

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
    gap: 12px;
    flex-wrap: wrap;
}
.toolbar__search {
    width: 260px;
    max-width: 100%;
}
.toolbar__sort {
    min-width: 190px;
}
.subbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.subbar__total {
    font-size: 12.5px;
    color: var(--text-3);
}
.mcard__date {
    margin-top: 2px;
    font-size: calc(var(--fs) - 2.5px);
    color: var(--text-3);
}
.info {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.info__preview {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    aspect-ratio: 16 / 10;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    background: var(--surface-3);
    color: var(--text-3);
    overflow: hidden;
}
.info__preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.info__busy {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: color-mix(in srgb, var(--surface) 60%, transparent);
}
.info__list {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 8px 16px;
    margin: 0;
    font-size: 13.5px;
}
.info__list dt {
    color: var(--text-3);
}
.info__list dd {
    margin: 0;
    color: var(--text);
    min-width: 0;
    overflow-wrap: anywhere;
}
.info__url {
    font-family: var(--font-mono);
    font-size: 12px;
}
.info__actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.info__hint {
    margin: 0;
    font-size: 12.5px;
    color: var(--text-3);
}
.info__file {
    display: none;
}

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
/* Action buttons (rename/delete) — top-right corner of the preview. */
.mcard__actions {
    position: absolute;
    top: 9px;
    right: 9px;
    z-index: 1;
    display: flex;
    gap: 6px;
}
.mcard__act {
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
