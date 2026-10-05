<script setup lang="ts">
import { computed, provide, ref, watch } from "vue";
import type { PropType } from "vue";
import { router } from "@inertiajs/vue3";
import { NEmptyState, NLightbox, NConfirmDialog } from "nergous-ui-vue";
import type { MediaDetails, MediaItem, Pagination } from "@/admin/types";
import { usePageHeader } from "@/admin/composables/usePageHeader";
import AdminPagination from "@/admin/components/AdminPagination.vue";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
import { useMediaDrag } from "@/admin/composables/useMediaDrag";
import {
    bulkFolderBody,
    useMediaSelection,
    type MediaSelectionPayload,
} from "@/admin/composables/useMediaSelection";
import type { UploadRules } from "@/admin/composables/useMediaUpload";
import { can } from "@/lib/can";
import { pluralize } from "@/lib/format";
import {
    baseName,
    fileName,
    parentOf,
    useCopyUrl,
    useThumbFallback,
    type FolderEntry,
} from "@/lib/media";
import MediaFolderDialogs from "./Partials/MediaFolderDialogs.vue";
import MediaGrid from "./Partials/MediaGrid.vue";
import MediaInfoDrawer from "./Partials/MediaInfoDrawer.vue";
import MediaList from "./Partials/MediaList.vue";
import MediaMoveModal from "./Partials/MediaMoveModal.vue";
import MediaPathbar from "./Partials/MediaPathbar.vue";
import MediaSelectionBar from "./Partials/MediaSelectionBar.vue";
import MediaToolbar from "./Partials/MediaToolbar.vue";
import MediaUploader from "./Partials/MediaUploader.vue";
import { mediaLibraryKey } from "./Partials/library";

type LibraryItem = MediaItem;

const props = defineProps({
    media: {
        type: Object as PropType<Pagination<LibraryItem>>,
        required: true,
    },
    currentSort: { type: String, default: "created_at" },
    currentDirection: { type: String, default: "desc" },
    perPage: { type: Number, default: 25 },
    perPageOptions: {
        type: Array as PropType<number[]>,
        default: () => [10, 25, 50, 100],
    },
    filters: {
        type: Object as PropType<{
            search?: string;
            type?: string;
            folder?: string;
            usage?: string;
        }>,
        default: () => ({}),
    },
    folders: { type: Array as PropType<FolderEntry[]>, default: () => [] },
    typeCounts: {
        type: Object as PropType<Record<string, number>>,
        default: () => ({}),
    },
    uploadRules: {
        type: Object as PropType<UploadRules>,
        default: () => ({ extensions: [], maxSizeKb: 51200, maxFiles: 10 }),
    },
});

// Keep polled uploads and local deletions alongside the server's page.
const rows = ref<LibraryItem[]>([...props.media.data]);
// A filter, sort or page change brings a new server page: replace the rows
// (the selection resets with it, see useMediaSelection).
watch(
    () => props.media,
    (media) => {
        rows.value = [...media.data];
    },
);

const filter = ref(props.filters.type || "all");
// The open folder; "" — the library root (files outside any folder + folders).
const folderFilter = ref(props.filters.folder ?? "");
// Browser back/forward restores the page props; keep the open folder in step.
watch(
    () => props.filters.folder,
    (folder) => {
        folderFilter.value = folder ?? "";
    },
);
const usageFilter = ref(props.filters.usage ?? "");
const search = ref(props.filters.search ?? "");
const sortKey = ref(`${props.currentSort}:${props.currentDirection}`);

const sortCol = computed(() => sortKey.value.split(":")[0]);
const sortDir = computed(() => sortKey.value.split(":")[1]);
// List view headers sort like a file manager: a click sorts by the column,
// a second click flips the direction.
function sortBy(col: string) {
    const firstDirection =
        col === "created_at" || col === "size" ? "desc" : "asc";
    const direction =
        sortCol.value === col
            ? sortDir.value === "asc"
                ? "desc"
                : "asc"
            : firstDirection;
    sortKey.value = `${col}:${direction}`;
    reload({ page: 1 });
}

const { reload, onSearch } = useIndexFilters("/admin/media", () => {
    const [sort, direction] = sortKey.value.split(":");
    return {
        search: search.value || undefined,
        type: filter.value === "all" ? undefined : filter.value,
        folder: folderFilter.value || undefined,
        usage: usageFilter.value || undefined,
        sort,
        direction,
        per_page: props.perPage,
    };
});
const hasFilters = computed(
    () =>
        filter.value !== "all" ||
        usageFilter.value !== "" ||
        search.value.trim() !== "",
);
const FILTER_LABELS: [string, string][] = [
    ["all", "Все"],
    ["image", "Фото"],
    ["video", "Видео"],
    ["audio", "Аудио"],
    ["document", "Документы"],
    ["other", "Другое"],
];
// Counts respect search and folder, but not the type filter itself.
const filterOpts = computed(() => {
    const total = Object.values(props.typeCounts).reduce((a, b) => a + b, 0);
    return FILTER_LABELS.map(([value, label]) => ({
        value,
        label: `${label} (${value === "all" ? total : (props.typeCounts[value] ?? 0)})`,
    }));
});
const folderNames = computed(() => props.folders.map((f) => f.name));
const currentFolder = computed(() => folderFilter.value);
const currentFolderEntry = computed(() =>
    props.folders.find((f) => f.name === currentFolder.value),
);
// At the root, search and the usage filter look through the whole library.
const wholeLibrary = computed(
    () =>
        !currentFolder.value &&
        (search.value.trim() !== "" || usageFilter.value !== ""),
);
// Subfolders of the open level head the list (first page only), like in a file
// manager. Search narrows them by name (at the root — across every level); the
// usage filter is about files, so it hides them.
const folderEntries = computed(() => {
    if (usageFilter.value) return [];
    if (props.media.current_page > 1) return [];
    const q = search.value.trim().toLocaleLowerCase();
    const list = props.folders.filter(
        (f) =>
            (wholeLibrary.value || parentOf(f.name) === currentFolder.value) &&
            (!q || baseName(f.name).toLocaleLowerCase().includes(q)),
    );
    return sortCol.value === "original_name" && sortDir.value === "desc"
        ? [...list].reverse()
        : list;
});
// Search results across levels show the full path, a level shows plain names.
function folderLabel(f: FolderEntry) {
    return wholeLibrary.value ? f.name : baseName(f.name);
}

// Opening a folder is a regular visit, so the browser back button returns.
// The open folder switches together with the server's answer (the
// props.filters.folder watcher): switching it earlier would filter the old
// rows by the new folder and flash an empty folder. Until then the old list
// stays on screen, dimmed.
const navigating = ref(false);
function openFolder(name: string) {
    const [sort, direction] = sortKey.value.split(":");
    router.get(
        "/admin/media",
        {
            folder: name || undefined,
            type: filter.value === "all" ? undefined : filter.value,
            sort,
            direction,
            per_page: props.perPage,
        },
        {
            preserveState: true,
            onStart: () => {
                navigating.value = true;
            },
            onSuccess: () => {
                search.value = "";
                usageFilter.value = "";
            },
            onFinish: () => {
                navigating.value = false;
            },
        },
    );
}
// The chosen view (grid or file list) survives page reloads.
const VIEW_KEY = "admin.media.view";
const view = ref(
    typeof localStorage !== "undefined" &&
        localStorage.getItem(VIEW_KEY) === "list"
        ? "list"
        : "grid",
);
watch(view, (value) => {
    try {
        localStorage.setItem(VIEW_KEY, value);
    } catch {
        // Storage may be unavailable (private mode); the view just won't persist.
    }
});

// The server already filters by type and folder; polled uploads are filtered here too.
function matchesFolder(m: LibraryItem) {
    if (wholeLibrary.value) return true;
    return (m.folder || "") === currentFolder.value;
}
const visible = computed(() =>
    rows.value.filter(
        (m) =>
            (filter.value === "all" || m.type === filter.value) &&
            matchesFolder(m),
    ),
);
const visibleIds = () => visible.value.map((m) => m.id);
const hasItems = computed(
    () => visible.value.length > 0 || folderEntries.value.length > 0,
);
// Lightbox indices refer to images only.
const images = computed(() => visible.value.filter((m) => m.type === "image"));
const lbItems = computed(() =>
    images.value.map((m) => ({
        url: m.url,
        caption: fileName(m),
    })),
);

/** Files of a running upload as the queue stores them: newest on top. */
function addPolled(items: LibraryItem[]) {
    // Poll results arrive newest first; prepend them in ascending order.
    const known = new Set(rows.value.map((m) => m.id));
    const add = items.filter((m) => !known.has(m.id));
    for (const m of [...add].reverse()) rows.value.unshift(m);
}

const lbIndex = ref(-1);
function openItem(m: MediaItem) {
    if (m.type === "image") {
        lbIndex.value = images.value.findIndex((x) => x.id === m.id);
    } else if (m.url) {
        // Non-images are opened/downloaded in a new tab.
        window.open(m.url, "_blank", "noopener");
    }
}

const thumbs = useThumbFallback();
const copyUrl = useCopyUrl();

const selection = useMediaSelection({
    visibleIds,
    total: () => props.media.total,
    filters: () => props.filters,
    page: () => props.media,
});
const {
    selected,
    allMatching,
    selectedCount,
    selectionPayload,
    clearSelection,
    deselect,
} = selection;
// Selection drives both bulk actions, so either permission enables it.
const canSelect = computed(() => can("media.delete") || can("media.edit"));

const delOpen = ref(false);
const delId = ref<number | null>(null);
const delLoading = ref(false);
// Name of the file being deleted, for the modal label (original_name → filename).
const delName = computed(() => {
    const m = rows.value.find((x) => x.id === delId.value);
    return m ? fileName(m) : "";
});
const delMessage = computed(() =>
    [
        delName.value
            ? `Удалить файл «${delName.value}»? Действие необратимо.`
            : "Удалить этот файл? Действие необратимо.",
        usageWarning(rows.value.filter((m) => m.id === delId.value)),
    ]
        .filter(Boolean)
        .join(" "),
);

// A referenced file is not deleted by the server (it would leave broken
// images in the settings or other places): the dialog says so up front.
function usageWarning(items: LibraryItem[]) {
    const used = items.filter((m) => m.usages?.length);
    if (!used.length) return "";
    if (items.length === 1) {
        return `Файл используется (${used[0].usages!.join(", ")}) — его не удалить, пока он подключён. Сначала замените его в этих местах.`;
    }
    const list = used
        .map((m) => `«${fileName(m)}» (${m.usages!.join(", ")})`)
        .join(", ");
    return `Используемые файлы будут пропущены: ${list}.`;
}
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
        onSuccess: (page) => {
            // Refused (the file is in use): the row stays.
            if (
                (page.props as { flash?: { error?: string | null } }).flash
                    ?.error
            ) {
                return;
            }
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
    if (allMatching.value)
        return `Удалить все файлы по текущему фильтру (${n} ${word})? Используемые файлы будут пропущены. Действие необратимо.`;
    return [
        `Удалить выбранные файлы (${n} ${word})? Действие необратимо.`,
        usageWarning(rows.value.filter((m) => selected.value.has(m.id))),
    ]
        .filter(Boolean)
        .join(" ");
});
function askBulkDelete() {
    if (selectedCount.value) bulkOpen.value = true;
}
function confirmBulkDelete() {
    const all = allMatching.value;
    const ids = [...selected.value];
    if (!all && !ids.length) {
        bulkOpen.value = false;
        return;
    }
    bulkLoading.value = true;
    router.delete("/admin/media/bulk", {
        data: selectionPayload(),
        preserveScroll: true,
        onSuccess: () => {
            // Files in use are skipped by the server (reported in a warning).
            const removed = new Set(
                ids.filter(
                    (id) =>
                        !rows.value.find((m) => m.id === id)?.usages?.length,
                ),
            );
            rows.value = rows.value.filter((m) => !removed.has(m.id));
            clearSelection();
        },
        onFinish: () => {
            bulkLoading.value = false;
            bulkOpen.value = false;
        },
    });
}

/**
 * Moves the picked (or every matching) file into a folder; an empty name
 * takes the files out of any folder. Rows on screen follow at once.
 */
function moveFiles(
    folder: string,
    payload: MediaSelectionPayload,
    callbacks: {
        onSuccess?: () => void;
        onError?: (errors: Record<string, string>) => void;
        onFinish?: () => void;
    } = {},
) {
    const ids = "ids" in payload ? payload.ids : [];
    router.patch("/admin/media/bulk-folder", bulkFolderBody(payload, folder), {
        preserveScroll: true,
        onSuccess: () => {
            const moved = new Set(ids);
            for (const m of rows.value) {
                if (moved.has(m.id)) m.folder = folder || null;
            }
            callbacks.onSuccess?.();
        },
        onError: (errors) => callbacks.onError?.(errors),
        onFinish: () => callbacks.onFinish?.(),
    });
}

// Bulk "move to folder" dialog.
const moveOpen = ref(false);
const moveFolder = ref("");
const moveError = ref("");
const moveLoading = ref(false);
function askMove() {
    if (!selectedCount.value) return;
    moveFolder.value = currentFolder.value;
    moveError.value = "";
    moveOpen.value = true;
}
function confirmMove() {
    if (!allMatching.value && !selected.value.size) {
        moveOpen.value = false;
        return;
    }
    moveLoading.value = true;
    moveFiles(moveFolder.value.trim(), selectionPayload(), {
        onSuccess: () => {
            clearSelection();
            moveOpen.value = false;
        },
        onError: (errors) => {
            moveError.value =
                errors.target ||
                errors.folder ||
                errors.ids ||
                Object.values(errors)[0] ||
                "Не удалось переместить файлы";
        },
        onFinish: () => {
            moveLoading.value = false;
        },
    });
}

// Drag cards (or the whole selection) onto a folder chip, tile or row to move them.
const drag = useMediaDrag(selection, {
    canDrag: () => can("media.edit"),
    visibleIds,
    total: () => props.media.total,
});
const { dragIds, dragCount, dropTarget, setDropTarget, onFolderDragLeave } =
    drag;
// Targets for the drop bar: the root and every folder except the open one.
const dropChips = computed(() =>
    ["", ...folderNames.value].filter((f) => f !== currentFolder.value),
);
function dropOnFolder(folder: string) {
    const { ids, all } = drag.take();
    if (!ids.length) return;
    moveFiles(folder, all ? selectionPayload() : { ids }, {
        onSuccess: () => {
            if (all) clearSelection();
            else deselect(ids);
        },
    });
}

// File details drawer and folder dialogs live in their own components.
const infoDrawer = ref<InstanceType<typeof MediaInfoDrawer> | null>(null);
function openInfo(m: MediaItem) {
    infoDrawer.value?.open(m);
}
function patchRow(id: number, patch: Partial<LibraryItem>) {
    const row = rows.value.find((m) => m.id === id);
    if (row) Object.assign(row, patch);
}
/** A replaced or cropped file: its row takes the new stored file. */
function applyDetails(details: MediaDetails) {
    patchRow(details.id, {
        filename: details.filename.split("/").pop() || details.filename,
        mime_type: details.mime_type,
        type: details.type,
        size: details.size,
        url: details.url,
        thumb_url: details.thumb_url,
        srcset: details.srcset,
        focal_x: details.focal_x,
        focal_y: details.focal_y,
        usages: details.usages,
    });
    thumbs.forget(details.id);
}

const folderDialogs = ref<InstanceType<typeof MediaFolderDialogs> | null>(null);
function askCreateFolder() {
    folderDialogs.value?.create();
}
function askRenameFolder(name = currentFolder.value) {
    folderDialogs.value?.rename(name);
}
function askClearFolder(name = currentFolder.value) {
    folderDialogs.value?.clear(name);
}

provide(mediaLibraryKey, {
    selection,
    drag,
    thumbs,
    canSelect,
    navigating,
    // The pencil opens the file drawer; without media.edit it is read-only.
    editLabel: computed(() =>
        can("media.edit") ? "Редактировать" : "Сведения о файле",
    ),
    folderLabel,
    openFolder,
    askRenameFolder,
    askClearFolder,
    dropOnFolder,
    openItem,
    openInfo,
    copyUrl,
    askDelete,
});

usePageHeader(() => ({
    title: "Медиатека",
    subtitle: "Файлы рабочего пространства",
}));
</script>

<template>
    <div class="page">
        <MediaUploader
            v-if="can('media.upload')"
            :upload-rules="uploadRules"
            :folder="currentFolder"
            @items="addPolled"
        />

        <MediaPathbar
            :current-folder="currentFolder"
            :entry="currentFolderEntry"
            @open="openFolder"
            @create="askCreateFolder"
            @rename="askRenameFolder()"
            @clear="askClearFolder()"
        />

        <MediaToolbar
            v-if="rows.length || hasFilters || folders.length"
            v-model:search="search"
            v-model:type="filter"
            v-model:usage="usageFilter"
            v-model:sort="sortKey"
            v-model:view="view"
            :current-folder="currentFolder"
            :filter-opts="filterOpts"
            @search="onSearch"
            @change="reload({ page: 1 })"
        />

        <MediaSelectionBar
            :total="media.total"
            :show-page-toggle="visible.length > 0 && view === 'grid'"
            @move="askMove"
            @delete="askBulkDelete"
        />

        <div
            v-if="dragIds.length"
            class="dropbar"
            role="region"
            aria-label="Переместить в папку"
        >
            <span class="dropbar__label"
                >Отпустите на папке ({{ dragCount }}
                {{ pluralize(dragCount, "файл", "файла", "файлов") }}):</span
            >
            <span
                v-for="f in dropChips"
                :key="f || '__none__'"
                class="folder-chip dropbar__chip"
                :class="{ 'folder-chip--on': dropTarget === f }"
                @dragenter.prevent="setDropTarget(f)"
                @dragover.prevent
                @dragleave="onFolderDragLeave(f)"
                @drop.prevent="dropOnFolder(f)"
                >{{ f || "Медиатека (без папки)" }}</span
            >
        </div>

        <MediaGrid
            v-if="hasItems && view === 'grid'"
            :folders="folderEntries"
            :items="visible"
        />
        <MediaList
            v-else-if="hasItems"
            :folders="folderEntries"
            :items="visible"
            :sort-col="sortCol"
            :sort-dir="sortDir"
            @sort="sortBy"
        />

        <NEmptyState
            v-else-if="currentFolder && !hasFilters"
            icon="asset"
            title="Папка пуста"
            description="Загрузите файлы выше — они сразу попадут в эту папку. Файлы из медиатеки можно перенести сюда кнопкой «Переместить в папку»."
        />
        <NEmptyState
            v-else-if="!hasFilters"
            icon="asset"
            title="Пока пусто"
            description="Загрузите первые файлы выше или создайте папку."
        />
        <NEmptyState
            v-else
            icon="filter"
            title="Ничего не найдено"
            description="Нет файлов по выбранному фильтру или запросу."
        />

        <AdminPagination
            :paginator="media"
            :per-page="perPage"
            :per-page-options="perPageOptions"
            @update:page="(p) => reload({ page: p })"
            @update:page-size="(size) => reload({ per_page: size, page: 1 })"
        />
    </div>

    <NLightbox
        :items="lbItems"
        v-model:index="lbIndex"
        dialog-label="Просмотр изображения"
        prev-label="Предыдущее фото"
        next-label="Следующее фото"
    />

    <MediaInfoDrawer
        ref="infoDrawer"
        :folder-names="folderNames"
        :upload-rules="uploadRules"
        @patch="patchRow"
        @replaced="applyDetails"
    />

    <MediaMoveModal
        v-model="moveOpen"
        v-model:folder="moveFolder"
        :folder-names="folderNames"
        :error="moveError"
        :loading="moveLoading"
        @confirm="confirmMove"
    />

    <NConfirmDialog
        v-model="delOpen"
        title="Удалить медиа"
        :message="delMessage"
        :loading="delLoading"
        @confirm="confirmDelete"
        danger
        confirm-label="Удалить"
    />

    <NConfirmDialog
        v-model="bulkOpen"
        title="Удалить медиа"
        :message="bulkMessage"
        :loading="bulkLoading"
        @confirm="confirmBulkDelete"
        danger
        confirm-label="Удалить"
    />

    <MediaFolderDialogs
        ref="folderDialogs"
        :current-folder="currentFolder"
        :folders="folders"
    />
</template>

<style scoped>
.dropbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border: 1px dashed var(--accent);
    border-radius: var(--radius-md);
    background: var(--accent-soft);
    position: sticky;
    top: 0;
    z-index: 5;
}
.dropbar__label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-2);
}
.folder-chip {
    padding: 2px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--text-2);
    font: inherit;
    font-size: 12.5px;
    cursor: pointer;
}
.folder-chip:hover,
.folder-chip--on {
    border-color: var(--accent);
    color: var(--text);
}
.dropbar__chip {
    padding: 6px 12px;
}
</style>
