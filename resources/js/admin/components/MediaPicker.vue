<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from "vue";
import type { PropType } from "vue";
import type { MediaItem } from "@/admin/types";
import {
    NModal,
    NAlert,
    NInput,
    NButton,
    NIcon,
    NProgress,
    NSpinner,
    NPagination,
    NEmptyState,
    useToast,
} from "nergous-ui-vue";
import { formatBytes } from "@/lib/format";
import { apiFetch, SessionExpiredError } from "@/lib/api";
import { focalStyle, typeBadge, typeIcon, useThumbFallback } from "@/lib/media";
import {
    acceptAttr,
    useMediaUpload,
    type PollDuplicate,
    type UploadRules,
} from "@/admin/composables/useMediaUpload";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    preselected: { type: Array as PropType<MediaItem[]>, default: () => [] },
    max: { type: Number, default: 10 },
    /** Restrict the library to one media type (image, video, audio, document). */
    type: { type: String, default: "" },
    title: { type: String, default: "Медиатека" },
    confirmLabel: { type: String, default: "Прикрепить" },
});
const emit = defineEmits(["update:modelValue", "select"]);

const toast = useToast();

const items = ref<MediaItem[]>([]);
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const search = ref("");
let searchTimer: ReturnType<typeof setTimeout> | null = null;
// Only the latest request may fill the grid: a slower answer for an older
// search or page is aborted instead of overwriting newer results.
let loadAbort: AbortController | null = null;

// Keep selected media across browse pages.
const selected = ref(new Map<number, MediaItem>());
const selectedCount = computed(() => selected.value.size);

function isSelected(id: number) {
    return selected.value.has(id);
}
function notifyLimit() {
    toast.info(
        "Достигнут лимит",
        `Можно прикрепить не более ${props.max} файлов.`,
    );
}
function toggle(m: MediaItem) {
    // Single-select mode: a click replaces the choice.
    if (props.max === 1) {
        selected.value = selected.value.has(m.id)
            ? new Map()
            : new Map([[m.id, m]]);
        return;
    }
    const next = new Map(selected.value);
    if (next.has(m.id)) {
        next.delete(m.id);
    } else {
        if (next.size >= props.max) {
            notifyLimit();
            return;
        }
        next.set(m.id, m);
    }
    selected.value = next;
}

const { showThumb, onImgError, reset: resetThumbs } = useThumbFallback();

// Upload rules arrive with the browse response; null means no uploads
// (no media.upload permission). Formats are already narrowed to the picker type.
const uploadRules = ref<UploadRules | null>(null);
const canUpload = computed(() => !!uploadRules.value?.extensions.length);
// One upload may not bring more files than the picker can select.
const pickerRules = computed<UploadRules>(() => ({
    extensions: uploadRules.value?.extensions ?? [],
    max_size_kb: uploadRules.value?.max_size_kb ?? 0,
    max_files: Math.min(uploadRules.value?.max_files ?? 0, props.max),
}));
const accept = computed(() => acceptAttr(pickerRules.value));
const fileInput = ref<HTMLInputElement | null>(null);
const dragOver = ref(false);

// Files uploaded in this picker (and found duplicates) go first in the grid
// and get selected automatically, but only once: a choice the user clears
// does not come back on the next poll.
const fresh = ref<MediaItem[]>([]);
let autoPicked = new Set<number>();
let limitNotified = false;
const shown = computed(() => {
    const ids = new Set(fresh.value.map((m) => m.id));
    return [...fresh.value, ...items.value.filter((m) => !ids.has(m.id))];
});

const {
    uploading,
    phase,
    pct,
    processingLabel,
    precheckErrors,
    upload,
    stopPolling,
} = useMediaUpload({
    rules: () => pickerRules.value,
    folder: () => "",
    onItems: adopt,
    onDuplicates: adoptDuplicates,
});

function adopt(list: MediaItem[]) {
    if (!props.modelValue) return;
    const incoming = list.filter((m) => !props.type || m.type === props.type);
    if (!incoming.length) return;
    const byId = new Map(fresh.value.map((m) => [m.id, m]));
    for (const m of incoming) byId.set(m.id, m);
    fresh.value = [...byId.values()].sort((a, b) => b.id - a.id);

    const next = new Map(selected.value);
    for (const m of incoming) {
        // Refresh an already selected record (e.g. its thumbnail is ready).
        if (next.has(m.id)) next.set(m.id, m);
        if (autoPicked.has(m.id)) continue;
        autoPicked.add(m.id);
        if (props.max === 1) {
            next.clear();
            next.set(m.id, m);
        } else if (!next.has(m.id)) {
            if (next.size >= props.max) {
                if (!limitNotified) notifyLimit();
                limitNotified = true;
                continue;
            }
            next.set(m.id, m);
        }
    }
    selected.value = next;
}

// The file is already in the library: take the existing record so it can
// be attached right away.
async function adoptDuplicates(list: PollDuplicate[]) {
    const ids = list
        .map((d) => d.existing_id)
        .filter((id): id is number => id != null);
    const found = await Promise.all(
        ids.map(async (id) => {
            try {
                const res = await apiFetch(`/admin/media/${id}`);
                return res.ok ? ((await res.json()) as MediaItem) : null;
            } catch {
                return null;
            }
        }),
    );
    adopt(found.filter((m): m is MediaItem => m !== null));
}

function startUpload(files: File[]) {
    if (!files.length || !canUpload.value) return;
    limitNotified = false;
    upload(files);
}

function onPick(e: Event) {
    const input = e.target as HTMLInputElement;
    startUpload(Array.from(input.files ?? []));
    input.value = "";
}

function hasFiles(e: DragEvent) {
    return !!e.dataTransfer?.types.includes("Files");
}
function onDragOver(e: DragEvent) {
    if (!canUpload.value || !hasFiles(e)) return;
    e.preventDefault();
    dragOver.value = true;
}
// dragleave also fires when moving onto a child element: drop the highlight
// only when the pointer really left the picker.
function onDragLeave(e: DragEvent) {
    const current = e.currentTarget;
    const related = e.relatedTarget;
    if (
        current instanceof HTMLElement &&
        (!(related instanceof Node) || !current.contains(related))
    ) {
        dragOver.value = false;
    }
}
function onDrop(e: DragEvent) {
    if (!canUpload.value || !hasFiles(e)) return;
    e.preventDefault();
    dragOver.value = false;
    startUpload(Array.from(e.dataTransfer?.files ?? []));
}

async function load(p = 1) {
    loadAbort?.abort();
    const controller = new AbortController();
    loadAbort = controller;
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: String(p) });
        if (search.value.trim()) params.set("search", search.value.trim());
        if (props.type) params.set("type", props.type);
        const res = await apiFetch(`/admin/media/browse?${params.toString()}`, {
            signal: controller.signal,
        });
        if (!res.ok) throw new Error(String(res.status));
        const json = await res.json();
        if (controller.signal.aborted) return;
        items.value = Array.isArray(json.data) ? json.data : [];
        page.value = json.current_page || 1;
        lastPage.value = json.last_page || 1;
        uploadRules.value = json.upload ?? null;
    } catch (err) {
        // Superseded by a newer request or the picker closed: not an error.
        if (controller.signal.aborted) return;
        items.value = [];
        if (err instanceof SessionExpiredError) return;
        toast.error(
            "Не удалось загрузить медиатеку",
            "Проверьте соединение и попробуйте снова.",
        );
    } finally {
        if (loadAbort === controller) {
            loadAbort = null;
            loading.value = false;
        }
    }
}

function onSearchInput() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 300);
}

/** Drops a pending debounced search and the request in flight. */
function cancelPending() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = null;
    loadAbort?.abort();
    loadAbort = null;
    loading.value = false;
}

// Seed selection from the parent's current attachments each time the modal opens.
watch(
    () => props.modelValue,
    (open) => {
        if (!open) {
            cancelPending();
            stopPolling();
            dragOver.value = false;
            return;
        }
        const seed = new Map<number, MediaItem>();
        for (const m of props.preselected) seed.set(m.id, m);
        selected.value = seed;
        fresh.value = [];
        autoPicked = new Set();
        precheckErrors.value = [];
        resetThumbs();
        search.value = "";
        load(1);
    },
);
onBeforeUnmount(cancelPending);

function close() {
    emit("update:modelValue", false);
}
function confirm() {
    emit("select", [...selected.value.values()]);
    close();
}
</script>

<template>
    <NModal
        :model-value="modelValue"
        :title="title"
        width="760px"
        @update:model-value="!$event && close()"
    >
        <div
            class="mp"
            :class="{ 'mp--drop': dragOver }"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            @drop="onDrop"
        >
            <div class="mp__head">
                <NInput
                    v-model="search"
                    type="search"
                    placeholder="Поиск по имени файла"
                    aria-label="Поиск по медиатеке"
                    @update:model-value="onSearchInput"
                />
                <span v-if="max > 1" class="mp__count">
                    Выбрано: {{ selectedCount }} / {{ max }}
                </span>
                <template v-if="canUpload">
                    <input
                        ref="fileInput"
                        class="mp__file"
                        type="file"
                        tabindex="-1"
                        aria-hidden="true"
                        :accept="accept"
                        :multiple="max > 1"
                        @change="onPick"
                    />
                    <NButton
                        variant="secondary"
                        icon="upload"
                        :disabled="uploading"
                        @click="fileInput?.click()"
                        >Загрузить</NButton
                    >
                </template>
            </div>

            <div
                v-if="uploading"
                class="mp__upload"
                role="status"
                aria-live="polite"
            >
                <NProgress
                    v-if="phase === 'uploading'"
                    :value="pct"
                    label="Загрузка"
                    show-value
                />
                <div v-else class="mp__processing">
                    <NSpinner :size="16" :width="2" />
                    <span>{{ processingLabel }}</span>
                </div>
            </div>
            <NAlert
                v-if="precheckErrors.length"
                tone="danger"
                title="Файлы не загружены"
            >
                <ul class="mp__errors">
                    <li v-for="(err, i) in precheckErrors" :key="i">
                        {{ err }}
                    </li>
                </ul>
            </NAlert>

            <div class="mp__body">
                <div v-if="loading" class="mp__loading">
                    <NSpinner :size="22" :width="2" />
                </div>

                <div v-else-if="shown.length" class="mp__grid">
                    <button
                        v-for="m in shown"
                        :key="m.id"
                        type="button"
                        class="mp__card"
                        :class="{ 'mp__card--on': isSelected(m.id) }"
                        :aria-pressed="isSelected(m.id)"
                        :title="m.original_name"
                        @click="toggle(m)"
                    >
                        <span class="mp__preview">
                            <img
                                v-if="showThumb(m)"
                                class="mp__img"
                                :src="m.thumb_url || undefined"
                                :style="focalStyle(m)"
                                :alt="m.original_name"
                                loading="lazy"
                                @error="onImgError(m.id)"
                            />
                            <span v-else class="mp__glyph">
                                <NIcon :name="typeIcon(m)" :size="28" />
                            </span>
                            <span class="mp__badge">{{ typeBadge(m) }}</span>
                            <span
                                v-if="isSelected(m.id)"
                                class="mp__tick"
                                aria-hidden="true"
                            >
                                <NIcon name="check" :size="14" />
                            </span>
                        </span>
                        <span class="mp__name">{{ m.original_name }}</span>
                        <span class="mp__size">{{ formatBytes(m.size) }}</span>
                    </button>
                </div>

                <NEmptyState
                    v-else
                    icon="asset"
                    title="Ничего не найдено"
                    :description="
                        search
                            ? 'Измените поисковый запрос.'
                            : canUpload
                              ? 'Медиатека пуста — перетащите файлы сюда или нажмите «Загрузить».'
                              : 'Медиатека пуста — загрузите файлы в разделе «Медиатека».'
                    "
                />
            </div>

            <div v-if="lastPage > 1" class="mp__pager">
                <NPagination
                    :page="page"
                    :pages="lastPage"
                    jumpable
                    aria-label="Навигация по медиатеке"
                    @update:page="load"
                />
            </div>
        </div>

        <template #footer>
            <div class="mp__footer">
                <NButton variant="ghost" @click="close">Отмена</NButton>
                <NButton
                    variant="primary"
                    :disabled="max === 1 && selectedCount === 0"
                    data-enter-submit
                    @click="confirm"
                >
                    {{ confirmLabel
                    }}{{
                        max > 1 && selectedCount ? ` (${selectedCount})` : ""
                    }}
                </NButton>
            </div>
        </template>
    </NModal>
</template>

<style scoped>
.mp {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-height: 320px;
}
.mp__head {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.mp__head :deep(.n-input-wrap),
.mp__head :deep(.n-field) {
    flex: 1;
    min-width: 200px;
}
.mp__count {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-2);
    white-space: nowrap;
}
/* Hidden file input: opened by the «Загрузить» button. */
.mp__file {
    display: none;
}
.mp__processing {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-2);
    font-size: 12.5px;
    font-weight: 600;
}
.mp__errors {
    margin: 0;
    padding-left: 18px;
}
.mp__body {
    position: relative;
    min-height: 260px;
    border-radius: var(--radius-md);
}
/* Files are dragged over the picker: highlight the grid area. */
.mp--drop .mp__body {
    outline: 2px dashed var(--accent);
    outline-offset: 2px;
    background: var(--accent-soft);
}
.mp--drop .mp__body::after {
    content: "Отпустите, чтобы загрузить";
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: var(--accent);
    background: var(--accent-soft);
    pointer-events: none;
}
.mp__loading {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 260px;
    color: var(--text-3);
}
.mp__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 12px;
}
.mp__card {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 0;
    background: transparent;
    border: none;
    cursor: pointer;
    text-align: left;
}
.mp__preview {
    position: relative;
    aspect-ratio: 16 / 10;
    background: var(--surface-3);
    background-image: repeating-linear-gradient(
        135deg,
        var(--border) 0 1px,
        transparent 1px 13px
    );
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    transition:
        border-color 0.14s ease,
        box-shadow 0.14s ease;
}
.mp__card--on .mp__preview {
    border-color: var(--accent);
    box-shadow:
        0 0 0 1px var(--accent),
        var(--shadow-sm);
}
.mp__card:focus-visible .mp__preview {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.mp__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.mp__glyph {
    color: var(--text-3);
    display: flex;
}
.mp__badge {
    position: absolute;
    bottom: 6px;
    left: 6px;
    font-size: 10px;
    font-weight: 700;
    background: var(--surface);
    color: var(--text-2);
    padding: 1px 6px;
    border-radius: 5px;
    border: 1px solid var(--border);
    font-family: var(--font-mono);
}
.mp__tick {
    position: absolute;
    top: 6px;
    right: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    box-shadow: var(--shadow-sm);
}
.mp__name {
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mp__size {
    font-size: 11px;
    color: var(--text-3);
}
.mp__pager {
    display: flex;
    justify-content: center;
    padding-top: 4px;
}
.mp__footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
</style>
