<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from "vue";
import type { PropType } from "vue";
import type { MediaDetails, MediaItem, Pagination } from "@/admin/types";
import { router } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NAlert,
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
import { csrfHeaders } from "@/lib/csrf";
import { apiFetch, redirectToLogin, SessionExpiredError } from "@/lib/api";
import { formatBytes, formatDateTime, pluralize } from "@/lib/format";

type LibraryItem = MediaItem;
type LibraryDetails = MediaDetails;
/** Mirrors MediaRequest validation so bad files are caught before upload. */
interface UploadRules {
    extensions: string[];
    maxSizeKb: number;
    maxFiles: number;
}
/** An upload_failed log entry reported by the poll endpoint. */
interface PollFailure {
    id: number;
    name: string;
    error: string;
}
/** A file skipped because the same content is already in the library. */
interface PollDuplicate {
    id: number;
    name: string;
    existing_id: number | null;
}
interface PollResult {
    items: LibraryItem[];
    failed: PollFailure[];
    duplicates: PollDuplicate[];
}

const props = defineProps({
    media: {
        type: Object as PropType<Pagination<LibraryItem>>,
        required: true,
    },
    currentSort: { type: String, default: "created_at" },
    currentDirection: { type: String, default: "desc" },
    perPage: { type: Number, default: 24 },
    perPageOptions: {
        type: Array as PropType<number[]>,
        default: () => [24, 48, 96],
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
    folders: { type: Array as PropType<string[]>, default: () => [] },
    folderCounts: {
        type: Object as PropType<Record<string, number>>,
        default: () => ({}),
    },
    typeCounts: {
        type: Object as PropType<Record<string, number>>,
        default: () => ({}),
    },
    uploadRules: {
        type: Object as PropType<UploadRules>,
        default: () => ({ extensions: [], maxSizeKb: 51200, maxFiles: 10 }),
    },
});

const toast = useToast();

// Keep polled uploads and local deletions alongside the server's page.
const rows = ref<LibraryItem[]>([...props.media.data]);
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
// "" — every folder, NO_FOLDER — files outside any folder (the server sentinel).
const NO_FOLDER = "__none__";
const folderFilter = ref(props.filters.folder ?? "");
const usageFilter = ref(props.filters.usage ?? "");
const usageOpts = [
    { value: "", label: "Все файлы" },
    { value: "used", label: "Используются" },
    { value: "unused", label: "Не используются" },
];
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
        folderFilter.value !== "" ||
        usageFilter.value !== "" ||
        search.value.trim() !== "",
);
const showPager = computed(
    () =>
        props.media.last_page > 1 ||
        props.media.total > Math.min(...props.perPageOptions),
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
const folderOpts = computed(() => [
    { value: "", label: "Все папки" },
    { value: NO_FOLDER, label: "Без папки" },
    ...props.folders.map((f) => ({
        value: f,
        label: props.folderCounts[f] ? `${f} (${props.folderCounts[f]})` : f,
    })),
]);
const view = ref("grid");
const viewOpts = [
    { value: "grid", icon: "grid", label: "Сетка" },
    { value: "list", icon: "list", label: "Список" },
];

// The server already filters by type and folder; polled uploads are filtered here too.
function matchesFolder(m: LibraryItem) {
    if (folderFilter.value === "") return true;
    if (folderFilter.value === NO_FOLDER) return !m.folder;
    return m.folder === folderFilter.value;
}
const visible = computed(() =>
    rows.value.filter(
        (m) =>
            (filter.value === "all" || m.type === filter.value) &&
            matchesFolder(m),
    ),
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
const failedCount = ref(0);
const duplicateCount = ref(0);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

// The queue count can lag behind received files; never show a negative value.
const processingLeft = computed(() =>
    Math.max(
        0,
        expectedCount.value -
            receivedCount.value -
            failedCount.value -
            duplicateCount.value,
    ),
);
const processingLabel = computed(() => {
    const n = processingLeft.value;
    if (n <= 0) return "Обработка файлов…";
    return `Обработка ${n} ${pluralize(n, "файла", "файлов", "файлов")}…`;
});

// Queue processing is watched for ~3 minutes; the pause between polls grows
// from 1 s to 10 s so a slow queue is not hammered.
const POLL_WINDOW_MS = 3 * 60 * 1000;
const POLL_FIRST_DELAY_MS = 1000;
const POLL_MAX_DELAY_MS = 10_000;
function nextPollDelay(delay: number) {
    return Math.min(Math.round(delay * 1.5), POLL_MAX_DELAY_MS);
}

const maxSizeBytes = computed(() => props.uploadRules.maxSizeKb * 1024);
const acceptAttr = computed(() =>
    props.uploadRules.extensions.map((e) => `.${e}`).join(","),
);
const dropHint = computed(
    () =>
        `изображения, видео, аудио, документы · до ${formatBytes(maxSizeBytes.value)} · не более ${props.uploadRules.maxFiles} за раз`,
);

// Client-side mirror of the server rules; the server stays the authority.
function checkFiles(files: File[], maxFiles = props.uploadRules.maxFiles) {
    const errors: string[] = [];
    if (files.length > maxFiles) {
        errors.push(
            `Можно загрузить не более ${maxFiles} ${pluralize(maxFiles, "файла", "файлов", "файлов")} за раз, выбрано ${files.length}.`,
        );
    }
    const allowed = new Set(
        props.uploadRules.extensions.map((e) => e.toLowerCase()),
    );
    for (const f of files) {
        const ext = f.name.includes(".")
            ? (f.name.split(".").pop() ?? "").toLowerCase()
            : "";
        if (!allowed.has(ext)) {
            errors.push(
                `«${f.name}»: недопустимый формат${ext ? ` .${ext}` : ""}.`,
            );
        } else if (f.size > maxSizeBytes.value) {
            errors.push(
                `«${f.name}»: ${formatBytes(f.size)}, допустимо не больше ${formatBytes(maxSizeBytes.value)}.`,
            );
        }
    }
    return errors;
}
const precheckErrors = ref<string[]>([]);

/** Progress of one upload request (the batch id returned by store/replace). */
async function fetchPoll(batch: string): Promise<PollResult> {
    const qs = new URLSearchParams({ batch });
    const res = await apiFetch(`/admin/media/poll?${qs}`);
    if (!res.ok) throw new Error(String(res.status));
    const json = await res.json();
    return {
        items: Array.isArray(json?.items) ? json.items : [],
        failed: Array.isArray(json?.failed) ? json.failed : [],
        duplicates: Array.isArray(json?.duplicates) ? json.duplicates : [],
    };
}

function reportFailures(failures: PollFailure[]) {
    if (!failures.length) return;
    const n = failures.length;
    toast.error(
        `Не удалось обработать ${n} ${pluralize(n, "файл", "файла", "файлов")}`,
        failures.map((f) => `«${f.name}»: ${f.error}`).join("; "),
    );
}

function reportDuplicates(duplicates: PollDuplicate[]) {
    if (!duplicates.length) return;
    const n = duplicates.length;
    toast.info(
        `${n} ${pluralize(n, "файл уже есть", "файла уже есть", "файлов уже есть")} в медиатеке`,
        `${duplicates.map((d) => `«${d.name}»`).join(", ")} — повторно не загружены.`,
    );
}

function upload(files: File[]) {
    if (!files?.length) return;
    precheckErrors.value = checkFiles(files);
    if (precheckErrors.value.length) return;
    // Cancel the previous upload's polling, otherwise a quick repeat drop leaves
    // an orphaned timer whose counters get clobbered by the new batch.
    stopPolling();
    const fd = new FormData();
    files.forEach((f) => fd.append("media[]", f));

    uploading.value = true;
    phase.value = "uploading";
    pct.value = 0;
    expectedCount.value = 0;
    receivedCount.value = 0;

    const xhr = new XMLHttpRequest();
    xhr.open("POST", "/admin/media");
    for (const [name, value] of Object.entries(csrfHeaders()))
        xhr.setRequestHeader(name, value);
    xhr.setRequestHeader("Accept", "application/json");
    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable)
            pct.value = Math.round((e.loaded / e.total) * 100);
    };
    xhr.onload = () => {
        if (xhr.status === 401 || xhr.status === 419) {
            stopPolling();
            redirectToLogin();
            return;
        }
        // onload also fires for 4xx and 5xx responses.
        if (xhr.status < 200 || xhr.status >= 300) {
            uploadFailed(xhr);
            return;
        }
        // Bytes delivered — the real work is now in the queue.
        pct.value = 100;
        let queued = files.length;
        let batch = "";
        try {
            const json = JSON.parse(xhr.responseText || "{}");
            if (typeof json.queued === "number") queued = json.queued;
            if (typeof json.batch === "string") batch = json.batch;
        } catch {
            // Fall back to the selected-file count when the response has no JSON.
        }
        if (!batch) {
            stopPolling();
            router.reload({ only: ["media", "typeCounts", "folders"] });
            return;
        }
        // The queue may continue after the upload reaches 100%.
        phase.value = "processing";
        startPolling(queued, batch);
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

// Stop polling when every queued file has arrived, failed or turned out to be a
// duplicate, or the window closes. The batch id scopes everything to this upload.
function startPolling(expected: number, batch: string) {
    expectedCount.value = expected;
    receivedCount.value = 0;
    failedCount.value = 0;
    duplicateCount.value = 0;
    const deadline = Date.now() + POLL_WINDOW_MS;
    const reported = new Set<number>();
    const reportedDuplicates = new Set<number>();
    let delay = POLL_FIRST_DELAY_MS;

    const tick = async () => {
        try {
            const { items, failed, duplicates } = await fetchPoll(batch);
            // Poll results arrive newest first; prepend them in ascending order.
            const known = new Set(rows.value.map((m) => m.id));
            const add = items.filter((m) => !known.has(m.id));
            for (const m of [...add].reverse()) rows.value.unshift(m);
            receivedCount.value = items.length;

            const fresh = failed.filter((f) => !reported.has(f.id));
            for (const f of fresh) reported.add(f.id);
            failedCount.value = reported.size;
            reportFailures(fresh);

            const freshDuplicates = duplicates.filter(
                (d) => !reportedDuplicates.has(d.id),
            );
            for (const d of freshDuplicates) reportedDuplicates.add(d.id);
            duplicateCount.value = reportedDuplicates.size;
            reportDuplicates(freshDuplicates);
        } catch (err) {
            if (err instanceof SessionExpiredError) {
                stopPolling();
                return;
            }
            // Retry transient network errors on the next tick.
        }

        if (
            receivedCount.value + failedCount.value + duplicateCount.value >=
            expected
        ) {
            stopPolling();
            return;
        }
        if (Date.now() + delay > deadline) {
            // The queue didn't finish within the window — don't hang, notify gently.
            stopPolling();
            toast.info(
                "Файлы ещё обрабатываются",
                "Обновите страницу через минуту, чтобы увидеть остальные.",
            );
            return;
        }
        pollTimer = setTimeout(tick, delay);
        delay = nextPollDelay(delay);
    };
    pollTimer = setTimeout(tick, delay);
    delay = nextPollDelay(delay);
}

function stopPolling() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
    uploading.value = false;
    phase.value = "uploading";
    pct.value = 0;
    expectedCount.value = 0;
    receivedCount.value = 0;
    failedCount.value = 0;
    duplicateCount.value = 0;
}
onBeforeUnmount(() => {
    stopPolling();
    if (replaceTimer) clearTimeout(replaceTimer);
});

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
    [
        delName.value
            ? `Удалить файл «${delName.value}»? Действие необратимо.`
            : "Удалить этот файл? Действие необратимо.",
        usageWarning(rows.value.filter((m) => m.id === delId.value)),
    ]
        .filter(Boolean)
        .join(" "),
);

// Deleting a file referenced by the settings breaks the favicon / OG image.
function usageWarning(items: LibraryItem[]) {
    const used = items.filter((m) => m.usages?.length);
    if (!used.length) return "";
    if (items.length === 1) {
        return `Внимание: файл используется (${used[0].usages!.join(", ")}) — после удаления там не будет изображения.`;
    }
    const list = used
        .map((m) => `«${fileName(m)}» (${m.usages!.join(", ")})`)
        .join(", ");
    return `Внимание: используются в настройках: ${list} — после удаления там не будет изображения.`;
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

// Bulk "move to folder"; an empty name takes the files out of any folder.
const moveOpen = ref(false);
const moveFolder = ref("");
const moveError = ref("");
const moveLoading = ref(false);
function askMove() {
    if (!selectedCount.value) return;
    moveFolder.value =
        folderFilter.value === NO_FOLDER ? "" : folderFilter.value;
    moveError.value = "";
    moveOpen.value = true;
}
function confirmMove() {
    const ids = [...selected.value];
    if (!ids.length) {
        moveOpen.value = false;
        return;
    }
    const folder = moveFolder.value.trim();
    moveLoading.value = true;
    router.patch(
        "/admin/media/bulk-folder",
        { ids, folder },
        {
            preserveScroll: true,
            onSuccess: () => {
                const moved = new Set(ids);
                for (const m of rows.value) {
                    if (moved.has(m.id)) m.folder = folder || null;
                }
                clearSelection();
                moveOpen.value = false;
            },
            onError: (errors) => {
                moveError.value =
                    errors.folder ||
                    errors.ids ||
                    Object.values(errors)[0] ||
                    "Не удалось переместить файлы";
            },
            onFinish: () => {
                moveLoading.value = false;
            },
        },
    );
}
// Selection drives both bulk actions, so either permission enables it.
const canSelect = computed(() => can("media.delete") || can("media.edit"));

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
const info = ref<LibraryDetails | null>(null);
const infoLoading = ref(false);

async function fetchDetails(id: number): Promise<LibraryDetails> {
    const res = await apiFetch(`/admin/media/${id}`);
    if (!res.ok) throw new Error(String(res.status));
    return (await res.json()) as LibraryDetails;
}

async function openInfo(m: LibraryItem) {
    info.value = {
        ...m,
        dimensions: null,
        uploaded_by: null,
        updated_by: null,
        updated_at: null,
    };
    syncMeta(info.value);
    infoOpen.value = true;
    infoLoading.value = true;
    try {
        info.value = await fetchDetails(m.id);
        syncMeta(info.value);
    } catch {
        toast.error("Не удалось загрузить сведения о файле");
    } finally {
        infoLoading.value = false;
    }
}

// Alt text and folder are edited inline in the drawer.
const metaAlt = ref("");
const metaFolder = ref("");
const metaErrors = ref<Record<string, string>>({});
const metaSaving = ref(false);
function syncMeta(d: LibraryItem) {
    metaAlt.value = d.alt ?? "";
    metaFolder.value = d.folder ?? "";
    metaErrors.value = {};
}
const metaDirty = computed(
    () =>
        !!info.value &&
        (metaAlt.value.trim() !== (info.value.alt ?? "") ||
            metaFolder.value.trim() !== (info.value.folder ?? "")),
);
function saveMeta() {
    const target = info.value;
    if (!target) return;
    const alt = metaAlt.value.trim();
    const folder = metaFolder.value.trim();
    metaSaving.value = true;
    router.patch(
        `/admin/media/${target.id}`,
        { alt, folder },
        {
            preserveScroll: true,
            onSuccess: () => {
                const patch = { alt: alt || null, folder: folder || null };
                const row = rows.value.find((m) => m.id === target.id);
                if (row) Object.assign(row, patch);
                if (info.value?.id === target.id) {
                    info.value = { ...info.value, ...patch };
                    syncMeta(info.value);
                }
            },
            onError: (errors) => {
                metaErrors.value = errors;
            },
            onFinish: () => {
                metaSaving.value = false;
            },
        },
    );
}

// Replacing the file keeps the record (id, name, attachments); the queue stores
// the new file under a new path, which the poll below waits for.
const replaceInput = ref<HTMLInputElement | null>(null);
const replacing = ref(false);
let replaceTimer: ReturnType<typeof setTimeout> | null = null;

function pickReplacement() {
    replaceInput.value?.click();
}

function applyDetails(details: LibraryDetails) {
    const row = rows.value.find((m) => m.id === details.id);
    if (row) {
        Object.assign(row, {
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

    const errors = checkFiles([file], 1);
    if (errors.length) {
        toast.error("Не удалось заменить файл", errors.join(" "));
        return;
    }

    const fd = new FormData();
    fd.append("file", file);
    replacing.value = true;

    apiFetch(`/admin/media/${target.id}/replace`, {
        method: "POST",
        body: fd,
    })
        .then(async (res) => {
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(
                    json.errors?.file?.[0] || json.message || "Ошибка загрузки",
                );
            }
            waitForReplacement(target.id, String(json.batch ?? ""));
        })
        .catch((err: Error) => {
            replacing.value = false;
            if (err instanceof SessionExpiredError) return;
            toast.error("Не удалось заменить файл", err.message);
        });
}

// Waits until the record shows up in the replacement batch (its row now carries
// the batch id) or the job reports a failure, with the same backoff window as uploads.
function waitForReplacement(id: number, batch: string) {
    if (replaceTimer) clearTimeout(replaceTimer);
    if (!batch) {
        replacing.value = false;
        return;
    }
    const deadline = Date.now() + POLL_WINDOW_MS;
    let delay = POLL_FIRST_DELAY_MS;

    const finish = () => {
        replaceTimer = null;
        replacing.value = false;
    };
    const tick = async () => {
        try {
            const poll = await fetchPoll(batch);
            if (poll.items.some((m) => m.id === id)) {
                const details = await fetchDetails(id);
                applyDetails(details);
                finish();
                toast.success("Файл заменён", fileName(details));
                return;
            }
            if (poll.failed.length) {
                finish();
                reportFailures(poll.failed);
                return;
            }
        } catch (err) {
            if (err instanceof SessionExpiredError) {
                finish();
                return;
            }
            // Transient error: retry on the next tick.
        }
        if (Date.now() + delay > deadline) {
            finish();
            toast.info(
                "Файл ещё обрабатывается",
                "Обновите страницу через минуту.",
            );
            return;
        }
        replaceTimer = setTimeout(tick, delay);
        delay = nextPollDelay(delay);
    };
    replaceTimer = setTimeout(tick, delay);
    delay = nextPollDelay(delay);
}
// Folder management: rename or dissolve the folder picked in the filter.
const currentFolder = computed(() =>
    folderFilter.value && folderFilter.value !== NO_FOLDER
        ? folderFilter.value
        : "",
);
const currentFolderCount = computed(
    () => props.folderCounts[currentFolder.value] ?? 0,
);
const folderRenOpen = ref(false);
const folderRenName = ref("");
const folderRenError = ref("");
const folderBusy = ref(false);
const folderClearOpen = ref(false);

function askRenameFolder() {
    folderRenName.value = currentFolder.value;
    folderRenError.value = "";
    folderRenOpen.value = true;
}
function confirmRenameFolder() {
    const folder = currentFolder.value;
    const name = folderRenName.value.trim();
    if (!name) {
        folderRenError.value = "Введите название папки";
        return;
    }
    folderBusy.value = true;
    router.patch(
        "/admin/media/folders",
        { folder, name },
        {
            onSuccess: () => {
                folderRenOpen.value = false;
                folderFilter.value = name;
            },
            onError: (errors) => {
                folderRenError.value =
                    errors.name ||
                    errors.folder ||
                    "Не удалось переименовать папку";
            },
            onFinish: () => {
                folderBusy.value = false;
            },
        },
    );
}
const folderClearMessage = computed(() => {
    const n = currentFolderCount.value;
    return `Удалить папку «${currentFolder.value}»? ${n} ${pluralize(n, "файл останется", "файла останутся", "файлов останутся")} в медиатеке без папки.`;
});
function confirmClearFolder() {
    folderBusy.value = true;
    router.delete("/admin/media/folders", {
        data: { folder: currentFolder.value },
        onSuccess: () => {
            folderFilter.value = "";
        },
        onFinish: () => {
            folderBusy.value = false;
            folderClearOpen.value = false;
        },
    });
}

// Drag cards (or the whole selection) onto a folder chip to move them.
const dragIds = ref<number[]>([]);
const dropTarget = ref<string | null>(null);
function onCardDragStart(e: DragEvent, m: LibraryItem) {
    if (!can("media.edit")) return;
    dragIds.value = selected.value.has(m.id) ? [...selected.value] : [m.id];
    if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = "move";
        e.dataTransfer.setData("text/plain", dragIds.value.join(","));
    }
}
function onCardDragEnd() {
    dragIds.value = [];
    dropTarget.value = null;
}
function dropOnFolder(folder: string) {
    const ids = dragIds.value;
    onCardDragEnd();
    if (!ids.length) return;
    router.patch(
        "/admin/media/bulk-folder",
        { ids, folder },
        {
            preserveScroll: true,
            onSuccess: () => {
                const moved = new Set(ids);
                for (const m of rows.value) {
                    if (moved.has(m.id)) m.folder = folder || null;
                }
                deselect(ids);
            },
        },
    );
}

/** Keeps the focal point in view when a card crops the thumbnail. */
function focalStyle(m: LibraryItem) {
    if (m.focal_x == null || m.focal_y == null) return undefined;
    return {
        objectPosition: `${Math.round(m.focal_x * 100)}% ${Math.round(m.focal_y * 100)}%`,
    };
}

interface Box {
    x: number;
    y: number;
    width: number;
    height: number;
}
const clamp01 = (v: number) => Math.min(1, Math.max(0, v));
function stagePoint(stage: HTMLElement | null, e: PointerEvent | MouseEvent) {
    const rect = stage?.getBoundingClientRect();
    if (!rect || !rect.width || !rect.height) return { x: 0, y: 0 };
    return {
        x: clamp01((e.clientX - rect.left) / rect.width),
        y: clamp01((e.clientY - rect.top) / rect.height),
    };
}

// Crop: the area is picked on the preview and sent as fractions (0..1).
const cropOpen = ref(false);
const cropStage = ref<HTMLElement | null>(null);
const cropBox = ref<Box>({ x: 0.1, y: 0.1, width: 0.8, height: 0.8 });
const cropAspect = ref("free");
const cropAspects = [
    { value: "free", label: "Свободно" },
    { value: "1", label: "1:1" },
    { value: "1.7778", label: "16:9" },
    { value: "1.3333", label: "4:3" },
];
const cropSaving = ref(false);
const cropError = ref("");
let cropDrag: {
    mode: "move" | "draw" | "resize";
    start: { x: number; y: number };
    box: Box;
} | null = null;

function imageRatio() {
    const d = info.value?.dimensions;
    return d && d.height ? d.width / d.height : 1;
}
/** Forces the chosen aspect ratio, shrinking the box to stay inside the image. */
function withAspect(box: Box): Box {
    if (cropAspect.value === "free") return box;
    // Height in fractions of the image height for the target pixel ratio.
    const factor = imageRatio() / Number(cropAspect.value);
    let width = box.width;
    let height = width * factor;
    if (box.y + height > 1) {
        height = 1 - box.y;
        width = height / factor;
    }
    if (box.x + width > 1) {
        width = 1 - box.x;
        height = width * factor;
    }
    return { ...box, width, height };
}
function openCrop() {
    cropAspect.value = "free";
    cropBox.value = { x: 0.1, y: 0.1, width: 0.8, height: 0.8 };
    cropError.value = "";
    cropOpen.value = true;
}
watch(cropAspect, () => {
    cropBox.value = withAspect(cropBox.value);
});
function onCropDown(e: PointerEvent) {
    const point = stagePoint(cropStage.value, e);
    const role = (e.target as HTMLElement).dataset.crop;
    const mode =
        role === "handle" ? "resize" : role === "box" ? "move" : "draw";
    cropDrag = { mode, start: point, box: { ...cropBox.value } };
    if (mode === "draw") cropBox.value = { ...point, width: 0, height: 0 };
    cropStage.value?.setPointerCapture(e.pointerId);
    e.preventDefault();
}
function onCropMove(e: PointerEvent) {
    if (!cropDrag) return;
    const point = stagePoint(cropStage.value, e);
    const { mode, start, box } = cropDrag;
    if (mode === "move") {
        cropBox.value = {
            ...box,
            x: Math.min(1 - box.width, Math.max(0, box.x + point.x - start.x)),
            y: Math.min(1 - box.height, Math.max(0, box.y + point.y - start.y)),
        };
        return;
    }
    const origin = mode === "draw" ? start : { x: box.x, y: box.y };
    cropBox.value = withAspect({
        x: Math.min(origin.x, point.x),
        y: Math.min(origin.y, point.y),
        width: Math.abs(point.x - origin.x),
        height: Math.abs(point.y - origin.y),
    });
}
function onCropUp() {
    if (!cropDrag) return;
    const tooSmall = cropBox.value.width < 0.02 || cropBox.value.height < 0.02;
    if (tooSmall) cropBox.value = cropDrag.box;
    cropDrag = null;
}
const cropPixels = computed(() => {
    const d = info.value?.dimensions;
    if (!d) return "";
    return `${Math.round(cropBox.value.width * d.width)} × ${Math.round(cropBox.value.height * d.height)} px`;
});
async function saveCrop() {
    const target = info.value;
    if (!target) return;
    cropSaving.value = true;
    cropError.value = "";
    try {
        const res = await apiFetch(`/admin/media/${target.id}/crop`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(cropBox.value),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            const errors = (json.errors ?? {}) as Record<string, string[]>;
            cropError.value =
                Object.values(errors)[0]?.[0] ||
                json.message ||
                "Не удалось обрезать изображение";
            return;
        }
        applyDetails(json as LibraryDetails);
        cropOpen.value = false;
        toast.success("Изображение обрезано", fileName(json as LibraryDetails));
    } catch (err) {
        if (!(err instanceof SessionExpiredError)) {
            cropError.value = "Нет связи с сервером";
        }
    } finally {
        cropSaving.value = false;
    }
}

// Focal point: a click on the preview; cards and pickers keep that spot in view.
const focalOpen = ref(false);
const focalStage = ref<HTMLElement | null>(null);
const focalPoint = ref<{ x: number; y: number } | null>(null);
const focalSaving = ref(false);
function openFocal() {
    const d = info.value;
    focalPoint.value =
        d && d.focal_x != null && d.focal_y != null
            ? { x: d.focal_x, y: d.focal_y }
            : null;
    focalOpen.value = true;
}
function pickFocal(e: MouseEvent) {
    focalPoint.value = stagePoint(focalStage.value, e);
}
function saveFocal() {
    const target = info.value;
    if (!target) return;
    const point = focalPoint.value;
    const patch = {
        focal_x: point ? Number(point.x.toFixed(4)) : null,
        focal_y: point ? Number(point.y.toFixed(4)) : null,
    };
    focalSaving.value = true;
    router.patch(`/admin/media/${target.id}`, patch, {
        preserveScroll: true,
        onSuccess: () => {
            const row = rows.value.find((m) => m.id === target.id);
            if (row) Object.assign(row, patch);
            if (info.value?.id === target.id) {
                info.value = { ...info.value, ...patch };
            }
            focalOpen.value = false;
        },
        onFinish: () => {
            focalSaving.value = false;
        },
    });
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
                    :accept="acceptAttr"
                    :hint="dropHint"
                    @files="upload"
                />
                <NAlert
                    v-if="precheckErrors.length"
                    class="upload-errors"
                    tone="danger"
                    title="Файлы не загружены"
                >
                    <ul class="upload-errors__list">
                        <li v-for="(err, i) in precheckErrors" :key="i">
                            {{ err }}
                        </li>
                    </ul>
                </NAlert>
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
                    <NSelect
                        v-model="folderFilter"
                        :options="folderOpts"
                        aria-label="Папка"
                        class="toolbar__folder"
                        @update:model-value="reload({ page: 1 })"
                    />
                    <NSelect
                        v-model="usageFilter"
                        :options="usageOpts"
                        aria-label="Использование"
                        class="toolbar__usage"
                        @update:model-value="reload({ page: 1 })"
                    />
                    <template v-if="currentFolder && can('media.edit')">
                        <NButton
                            variant="ghost"
                            size="sm"
                            icon="edit"
                            @click="askRenameFolder"
                            >Переименовать папку</NButton
                        >
                        <NButton
                            variant="ghost"
                            size="sm"
                            tone="danger"
                            icon="trash"
                            @click="folderClearOpen = true"
                            >Удалить папку</NButton
                        >
                    </template>
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

            <div v-if="canSelect && visible.length" class="subbar">
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
                v-if="canSelect && selectedCount"
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
                        v-if="can('media.edit')"
                        variant="secondary"
                        size="sm"
                        icon="layers"
                        @click="askMove"
                    >
                        Переместить в папку
                    </NButton>
                    <NButton
                        v-if="can('media.delete')"
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
                v-if="dragIds.length"
                class="dropbar"
                role="region"
                aria-label="Переместить в папку"
            >
                <span class="dropbar__label"
                    >Отпустите на папке ({{ dragIds.length }}
                    {{
                        pluralize(dragIds.length, "файл", "файла", "файлов")
                    }}):</span
                >
                <span
                    v-for="f in ['', ...folders]"
                    :key="f || '__none__'"
                    class="folder-chip dropbar__chip"
                    :class="{ 'folder-chip--on': dropTarget === f }"
                    @dragenter.prevent="dropTarget = f"
                    @dragover.prevent
                    @dragleave="dropTarget === f && (dropTarget = null)"
                    @drop.prevent="dropOnFolder(f)"
                    >{{ f || "Без папки" }}</span
                >
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
                    :class="{
                        'mcard--selected': isSelected(m.id),
                        'mcard--dragging': dragIds.includes(m.id),
                    }"
                    :draggable="can('media.edit')"
                    @dragstart="onCardDragStart($event, m)"
                    @dragend="onCardDragEnd"
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
                                :srcset="m.srcset || undefined"
                                sizes="(max-width: 600px) 50vw, 260px"
                                :style="focalStyle(m)"
                                :alt="m.alt || m.original_name || m.filename"
                                loading="lazy"
                                draggable="false"
                                @error="onImgError(m.id)"
                            />
                            <span v-else class="mcard__glyph">
                                <NIcon :name="typeIcon(m)" :size="34" />
                            </span>
                        </button>

                        <span
                            v-if="canSelect"
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
                            <span
                                v-if="m.folder"
                                class="mcard__folder"
                                :title="'Папка: ' + m.folder"
                                >{{ m.folder }}</span
                            >
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
                        :srcset="info.srcset || undefined"
                        sizes="420px"
                        :alt="info.alt || fileName(info)"
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
                    <template v-if="!can('media.edit')">
                        <dt>Папка</dt>
                        <dd>{{ info.folder || "—" }}</dd>
                        <dt>Alt-текст</dt>
                        <dd>{{ info.alt || "—" }}</dd>
                    </template>
                </dl>
                <NAlert
                    v-if="info.usages?.length"
                    tone="warn"
                    title="Файл используется"
                >
                    {{ info.usages.join(", ") }}. После удаления или замены
                    обновите изображение в настройках.
                </NAlert>
                <div v-if="can('media.edit')" class="info__meta">
                    <NFormField
                        label="Alt-текст"
                        :error="metaErrors.alt"
                        hint="Описание изображения для экранных дикторов и поисковиков."
                    >
                        <NInput
                            v-model="metaAlt"
                            :error="!!metaErrors.alt"
                            placeholder="Что изображено"
                        />
                    </NFormField>
                    <NFormField
                        label="Папка"
                        :error="metaErrors.folder"
                        hint="Оставьте пустым, чтобы убрать файл из папки."
                    >
                        <NInput
                            v-model="metaFolder"
                            :error="!!metaErrors.folder"
                            placeholder="Без папки"
                        />
                    </NFormField>
                    <div v-if="folders.length" class="folder-chips">
                        <button
                            v-for="f in folders"
                            :key="f"
                            type="button"
                            class="folder-chip"
                            :class="{
                                'folder-chip--on': metaFolder.trim() === f,
                            }"
                            @click="metaFolder = f"
                        >
                            {{ f }}
                        </button>
                    </div>
                    <div>
                        <NButton
                            variant="primary"
                            size="sm"
                            :disabled="!metaDirty"
                            :loading="metaSaving"
                            @click="saveMeta"
                            >Сохранить</NButton
                        >
                    </div>
                </div>
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
                    <template
                        v-if="
                            can('media.edit') &&
                            info.type === 'image' &&
                            info.dimensions
                        "
                    >
                        <NButton
                            variant="secondary"
                            icon="grid"
                            :disabled="replacing"
                            @click="openCrop"
                            >Обрезать</NButton
                        >
                        <NButton
                            variant="secondary"
                            icon="eye"
                            :disabled="replacing"
                            @click="openFocal"
                            >Точка фокуса</NButton
                        >
                    </template>
                </div>
                <p v-if="can('media.edit')" class="info__hint">
                    При замене запись и имя сохраняются, но адрес файла меняется
                    — внешние ссылки на старый адрес перестанут работать.
                </p>
                <input
                    ref="replaceInput"
                    type="file"
                    class="info__file"
                    :accept="acceptAttr"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="onReplaceFile"
                />
            </div>
        </NDrawer>

        <NModal
            v-model="moveOpen"
            title="Переместить в папку"
            width="420px"
            close-label="Закрыть"
        >
            <NFormField
                label="Папка"
                :error="moveError"
                hint="Новая папка создаётся по имени. Пустое поле убирает файлы из папок."
            >
                <NInput
                    v-model="moveFolder"
                    :error="!!moveError"
                    placeholder="Без папки"
                    @keyup.enter="confirmMove"
                />
            </NFormField>
            <div v-if="folders.length" class="folder-chips">
                <button
                    v-for="f in folders"
                    :key="f"
                    type="button"
                    class="folder-chip"
                    :class="{ 'folder-chip--on': moveFolder.trim() === f }"
                    @click="moveFolder = f"
                >
                    {{ f }}
                </button>
            </div>
            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton
                    variant="primary"
                    block
                    :loading="moveLoading"
                    @click="confirmMove"
                    >Переместить</NButton
                >
            </template>
        </NModal>

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

        <NModal
            v-model="folderRenOpen"
            title="Переименовать папку"
            width="420px"
            close-label="Закрыть"
        >
            <NFormField
                label="Новое название"
                :error="folderRenError"
                hint="Если папка с таким названием уже есть, файлы объединятся."
                required
            >
                <NInput
                    v-model="folderRenName"
                    :error="!!folderRenError"
                    @keyup.enter="confirmRenameFolder"
                />
            </NFormField>
            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton
                    variant="primary"
                    block
                    :loading="folderBusy"
                    @click="confirmRenameFolder"
                    >Сохранить</NButton
                >
            </template>
        </NModal>

        <ConfirmModal
            :open="folderClearOpen"
            title="Удалить папку"
            :message="folderClearMessage"
            :loading="folderBusy"
            @confirm="confirmClearFolder"
            @cancel="folderClearOpen = false"
            @update:open="folderClearOpen = $event"
        />

        <NModal
            v-model="cropOpen"
            title="Обрезать изображение"
            width="760px"
            close-label="Закрыть"
        >
            <div v-if="info" class="imgedit">
                <div class="imgedit__bar">
                    <NSegmented
                        v-model="cropAspect"
                        :options="cropAspects"
                        aria-label="Пропорции"
                    />
                    <span class="imgedit__size">{{ cropPixels }}</span>
                </div>
                <div
                    ref="cropStage"
                    class="imgstage imgstage--crop"
                    @pointerdown="onCropDown"
                    @pointermove="onCropMove"
                    @pointerup="onCropUp"
                    @pointercancel="onCropUp"
                >
                    <img
                        :src="info.url"
                        :alt="info.alt || fileName(info)"
                        draggable="false"
                    />
                    <div
                        class="cropbox"
                        data-crop="box"
                        :style="{
                            left: cropBox.x * 100 + '%',
                            top: cropBox.y * 100 + '%',
                            width: cropBox.width * 100 + '%',
                            height: cropBox.height * 100 + '%',
                        }"
                    >
                        <span class="cropbox__handle" data-crop="handle" />
                    </div>
                </div>
                <p class="imgedit__hint">
                    Выделите область мышью, перетащите рамку или потяните за
                    угол. Будет создан новый файл, адрес изображения изменится.
                </p>
                <NAlert v-if="cropError" tone="danger">{{ cropError }}</NAlert>
            </div>
            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton
                    variant="primary"
                    block
                    :loading="cropSaving"
                    @click="saveCrop"
                    >Обрезать</NButton
                >
            </template>
        </NModal>

        <NModal
            v-model="focalOpen"
            title="Точка фокуса"
            width="640px"
            close-label="Закрыть"
        >
            <div v-if="info" class="imgedit">
                <p class="imgedit__hint">
                    Нажмите на главное в кадре. При обрезке карточками и превью
                    эта точка останется видимой.
                </p>
                <div
                    ref="focalStage"
                    class="imgstage imgstage--focal"
                    @click="pickFocal"
                >
                    <img
                        :src="info.url"
                        :alt="info.alt || fileName(info)"
                        draggable="false"
                    />
                    <span
                        v-if="focalPoint"
                        class="focal-dot"
                        :style="{
                            left: focalPoint.x * 100 + '%',
                            top: focalPoint.y * 100 + '%',
                        }"
                    />
                </div>
            </div>
            <template #footer="{ close }">
                <NButton
                    variant="ghost"
                    block
                    :disabled="!focalPoint"
                    @click="focalPoint = null"
                    >По центру</NButton
                >
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton
                    variant="primary"
                    block
                    :loading="focalSaving"
                    @click="saveFocal"
                    >Сохранить</NButton
                >
            </template>
        </NModal>
    </AdminLayout>
</template>

<style scoped>
.toolbar__usage {
    min-width: 170px;
}
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
.dropbar__chip {
    padding: 6px 12px;
}
.mcard--dragging {
    opacity: 0.5;
}
.imgedit {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.imgedit__bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.imgedit__size {
    font-size: 12.5px;
    color: var(--text-3);
    font-variant-numeric: tabular-nums;
}
.imgedit__hint {
    margin: 0;
    font-size: 12.5px;
    color: var(--text-3);
}
/* The stage shrink-wraps the image, so pointer offsets map to image fractions. */
.imgstage {
    position: relative;
    align-self: center;
    display: inline-block;
    max-width: 100%;
    line-height: 0;
    overflow: hidden;
    border-radius: var(--radius-md);
    user-select: none;
    touch-action: none;
}
.imgstage img {
    display: block;
    max-width: 100%;
    max-height: 60vh;
    pointer-events: none;
}
.imgstage--crop {
    cursor: crosshair;
}
.imgstage--focal {
    cursor: pointer;
}
.cropbox {
    position: absolute;
    box-sizing: border-box;
    border: 2px solid #fff;
    box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
    cursor: move;
}
.cropbox__handle {
    position: absolute;
    right: -7px;
    bottom: -7px;
    width: 14px;
    height: 14px;
    border-radius: 3px;
    background: #fff;
    border: 2px solid var(--accent);
    cursor: nwse-resize;
}
.focal-dot {
    position: absolute;
    width: 22px;
    height: 22px;
    margin: -11px 0 0 -11px;
    border-radius: 50%;
    border: 3px solid #fff;
    background: var(--accent);
    box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.45);
    pointer-events: none;
}
.upload-progress {
    margin-top: 16px;
}
.upload-errors {
    margin-top: 16px;
}
.upload-errors__list {
    margin: 0;
    padding-left: 18px;
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
.toolbar__folder {
    min-width: 170px;
}
.mcard__folder {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.info__meta {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.folder-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
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
