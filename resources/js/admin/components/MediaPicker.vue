<script setup>
// MediaPicker — a modal to pick one or several files from the media library.
// Browses /admin/media/browse (JSON, paginated, searchable), keeps a multi-select
// that survives paging, and emits the chosen media objects on confirm. Reusable:
// gated by media.view on the server, so show the trigger only when can('media.view').
import { ref, computed, watch } from "vue";
import {
    NModal,
    NInput,
    NButton,
    NIcon,
    NSpinner,
    NPagination,
    NEmptyState,
    useToast,
} from "@/lib/nergous-cit";
import { formatBytes } from "@/lib/format.js";

const props = defineProps({
    // v-model: open state.
    modelValue: { type: Boolean, default: false },
    // Already-attached media objects ({ id, url, thumb_url, type, original_name, size }),
    // used to seed the selection when the modal opens.
    preselected: { type: Array, default: () => [] },
    // Max files that can be selected (mirrors config('bot.max_attachments')).
    max: { type: Number, default: 10 },
});
const emit = defineEmits(["update:modelValue", "select"]);

const toast = useToast();

/* ── Type glyphs (no thumbnail → an icon by category) — same mapping as Media/Index ── */
const TYPE_ICON = {
    image: "asset",
    video: "layers",
    audio: "activity",
    document: "copy",
    other: "asset",
};
function typeIcon(m) {
    return TYPE_ICON[m.type] || TYPE_ICON.other;
}
function typeBadge(m) {
    const name = m.original_name || "";
    const ext = name.includes(".") ? name.split(".").pop() : "";
    return (ext || m.type || "").toUpperCase().slice(0, 5);
}

/* ── Browse state ─────────────────────────────────────────────────────── */
const items = ref([]);
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const search = ref("");
let searchTimer = null;

/* ── Selection (id → media object); a Map so it survives paging. ───────── */
const selected = ref(new Map());
const selectedCount = computed(() => selected.value.size);

function isSelected(id) {
    return selected.value.has(id);
}
function toggle(m) {
    const next = new Map(selected.value);
    if (next.has(m.id)) {
        next.delete(m.id);
    } else {
        if (next.size >= props.max) {
            toast.info(
                "Достигнут лимит",
                `Можно прикрепить не более ${props.max} файлов.`,
            );
            return;
        }
        next.set(m.id, m);
    }
    selected.value = next;
}

/* ── Broken thumbnails fall back to the type glyph ────────────────────── */
const broken = ref(new Set());
function onImgError(id) {
    const next = new Set(broken.value);
    next.add(id);
    broken.value = next;
}
function showThumb(m) {
    return m.type === "image" && !!m.thumb_url && !broken.value.has(m.id);
}

async function load(p = 1) {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: String(p) });
        if (search.value.trim()) params.set("search", search.value.trim());
        const res = await fetch(`/admin/media/browse?${params.toString()}`, {
            headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error(String(res.status));
        const json = await res.json();
        items.value = Array.isArray(json.data) ? json.data : [];
        page.value = json.current_page || 1;
        lastPage.value = json.last_page || 1;
    } catch {
        items.value = [];
        toast.error(
            "Не удалось загрузить медиатеку",
            "Проверьте соединение и попробуйте снова.",
        );
    } finally {
        loading.value = false;
    }
}

function onSearchInput() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 300);
}

// Seed selection from the parent's current attachments each time the modal opens.
watch(
    () => props.modelValue,
    (open) => {
        if (!open) return;
        const seed = new Map();
        for (const m of props.preselected) seed.set(m.id, m);
        selected.value = seed;
        broken.value = new Set();
        search.value = "";
        load(1);
    },
);

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
        title="Медиатека"
        width="760px"
        close-label="Закрыть"
        @update:model-value="!$event && close()"
    >
        <div class="mp">
            <div class="mp__head">
                <NInput
                    v-model="search"
                    type="search"
                    placeholder="Поиск по имени файла"
                    aria-label="Поиск по медиатеке"
                    @update:model-value="onSearchInput"
                />
                <span class="mp__count">
                    Выбрано: {{ selectedCount }} / {{ max }}
                </span>
            </div>

            <div class="mp__body">
                <div v-if="loading" class="mp__loading">
                    <NSpinner :size="22" :width="2" />
                </div>

                <div v-else-if="items.length" class="mp__grid">
                    <button
                        v-for="m in items"
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
                                :src="m.thumb_url"
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
                            : 'Медиатека пуста — загрузите файлы в разделе «Медиатека».'
                    "
                />
            </div>

            <div v-if="lastPage > 1" class="mp__pager">
                <NPagination
                    :page="page"
                    :pages="lastPage"
                    prev-label="Назад"
                    next-label="Вперёд"
                    aria-label="Навигация по медиатеке"
                    @update:page="load"
                />
            </div>
        </div>

        <template #footer>
            <div class="mp__footer">
                <NButton variant="ghost" @click="close">Отмена</NButton>
                <NButton variant="primary" @click="confirm">
                    Прикрепить{{ selectedCount ? ` (${selectedCount})` : "" }}
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
.mp__body {
    min-height: 260px;
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
