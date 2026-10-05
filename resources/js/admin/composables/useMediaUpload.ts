import { computed, onBeforeUnmount, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { useToast } from "nergous-ui-vue";
import type { MediaItem } from "@/admin/types";
import { csrfHeaders } from "@/lib/csrf";
import {
    apiFetch,
    redirectToLogin,
    SessionExpiredError,
    touchSession,
} from "@/lib/api";
import { formatBytes, pluralize } from "@/lib/format";

/** Mirrors MediaRequest validation so bad files are caught before upload. */
export interface UploadRules {
    extensions: string[];
    maxSizeKb: number;
    maxFiles: number;
}
/** An upload_failed log entry reported by the poll endpoint. */
export interface PollFailure {
    id: number;
    name: string;
    error: string;
}
/** A file skipped because the same content is already in the library. */
export interface PollDuplicate {
    id: number;
    name: string;
    existing_id: number | null;
}
export interface PollResult {
    items: MediaItem[];
    failed: PollFailure[];
    duplicates: PollDuplicate[];
}

type Toast = ReturnType<typeof useToast>;

// Queue processing is watched for ~3 minutes; the pause between polls grows
// from 1 s to 10 s so a slow queue is not hammered.
export const POLL_WINDOW_MS = 3 * 60 * 1000;
export const POLL_FIRST_DELAY_MS = 1000;
const POLL_MAX_DELAY_MS = 10_000;
export function nextPollDelay(delay: number) {
    return Math.min(Math.round(delay * 1.5), POLL_MAX_DELAY_MS);
}

/** The file input "accept" list for the allowed extensions. */
export function acceptAttr(rules: UploadRules) {
    return rules.extensions.map((e) => `.${e}`).join(",");
}

/** Client-side mirror of the server rules; the server stays the authority. */
export function checkFiles(
    files: File[],
    rules: UploadRules,
    maxFiles = rules.maxFiles,
) {
    const errors: string[] = [];
    const maxSizeBytes = rules.maxSizeKb * 1024;
    if (files.length > maxFiles) {
        errors.push(
            `Можно загрузить не более ${maxFiles} ${pluralize(maxFiles, "файла", "файлов", "файлов")} за раз, выбрано ${files.length}.`,
        );
    }
    const allowed = new Set(rules.extensions.map((e) => e.toLowerCase()));
    for (const f of files) {
        const ext = f.name.includes(".")
            ? (f.name.split(".").pop() ?? "").toLowerCase()
            : "";
        if (!allowed.has(ext)) {
            errors.push(
                `«${f.name}»: недопустимый формат${ext ? ` .${ext}` : ""}.`,
            );
        } else if (f.size > maxSizeBytes) {
            errors.push(
                `«${f.name}»: ${formatBytes(f.size)}, допустимо не больше ${formatBytes(maxSizeBytes)}.`,
            );
        }
    }
    return errors;
}

/** Progress of one upload request (the batch id returned by store/replace). */
export async function fetchPoll(batch: string): Promise<PollResult> {
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

export function reportFailures(toast: Toast, failures: PollFailure[]) {
    if (!failures.length) return;
    const n = failures.length;
    toast.error(
        `Не удалось обработать ${n} ${pluralize(n, "файл", "файла", "файлов")}`,
        failures.map((f) => `«${f.name}»: ${f.error}`).join("; "),
    );
}

function reportDuplicates(toast: Toast, duplicates: PollDuplicate[]) {
    if (!duplicates.length) return;
    const n = duplicates.length;
    toast.info(
        `${n} ${pluralize(n, "файл уже есть", "файла уже есть", "файлов уже есть")} в медиатеке`,
        `${duplicates.map((d) => `«${d.name}»`).join(", ")} — повторно не загружены.`,
    );
}

/**
 * Library upload: an XHR with transfer progress, then polling of the queue
 * that stores the files. Polled files are handed to onItems as they arrive.
 */
export function useMediaUpload(options: {
    rules: () => UploadRules;
    /** Folder the files go into ("" — the library root). */
    folder: () => string;
    onItems: (items: MediaItem[]) => void;
}) {
    const toast = useToast();

    // Upload progress covers transfer; polling tracks subsequent queue processing.
    const uploading = ref(false);
    const phase = ref<"uploading" | "processing">("uploading");
    const pct = ref(0);
    const expectedCount = ref(0);
    const receivedCount = ref(0);
    const failedCount = ref(0);
    const duplicateCount = ref(0);
    const precheckErrors = ref<string[]>([]);
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

    function upload(files: File[]) {
        if (!files?.length) return;
        precheckErrors.value = checkFiles(files, options.rules());
        if (precheckErrors.value.length) return;
        // Cancel the previous upload's polling, otherwise a quick repeat drop leaves
        // an orphaned timer whose counters get clobbered by the new batch.
        stopPolling();
        const fd = new FormData();
        files.forEach((f) => fd.append("media[]", f));
        // Files uploaded inside a folder go into it.
        const folder = options.folder();
        if (folder) fd.append("folder", folder);

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
            // The server accepted the session, so it was extended: keep the
            // idle timer in step, like apiFetch does for fetch requests.
            touchSession();
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
                options.onItems(items);
                receivedCount.value = items.length;

                const fresh = failed.filter((f) => !reported.has(f.id));
                for (const f of fresh) reported.add(f.id);
                failedCount.value = reported.size;
                reportFailures(toast, fresh);

                const freshDuplicates = duplicates.filter(
                    (d) => !reportedDuplicates.has(d.id),
                );
                for (const d of freshDuplicates) reportedDuplicates.add(d.id);
                duplicateCount.value = reportedDuplicates.size;
                reportDuplicates(toast, freshDuplicates);
            } catch (err) {
                if (err instanceof SessionExpiredError) {
                    stopPolling();
                    return;
                }
                // Retry transient network errors on the next tick.
            }

            if (
                receivedCount.value +
                    failedCount.value +
                    duplicateCount.value >=
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

    onBeforeUnmount(stopPolling);

    return {
        uploading,
        phase,
        pct,
        processingLabel,
        precheckErrors,
        upload,
        stopPolling,
    };
}
