<script setup lang="ts">
import type { PropType } from "vue";
import { NButton, NCheckbox, NIcon } from "nergous-ui-vue";
import type { MediaItem } from "@/admin/types";
import { can } from "@/lib/can";
import { formatBytes, formatDateTime } from "@/lib/format";
import {
    fileName,
    focalStyle,
    folderSummary,
    typeBadge,
    typeIcon,
    typeLabel,
    type FolderEntry,
} from "@/lib/media";
import FolderGlyph from "./FolderGlyph";
import { openTitle, useMediaLibrary } from "./library";

const props = defineProps({
    folders: { type: Array as PropType<FolderEntry[]>, default: () => [] },
    items: { type: Array as PropType<MediaItem[]>, default: () => [] },
    sortCol: { type: String, required: true },
    sortDir: { type: String, required: true },
});
const emit = defineEmits<{ sort: [col: string] }>();

const {
    selection,
    drag,
    thumbs,
    canSelect,
    navigating,
    editLabel,
    folderLabel,
    openFolder,
    askRenameFolder,
    askClearFolder,
    dropOnFolder,
    openItem,
    openInfo,
    copyUrl,
    askDelete,
} = useMediaLibrary();
const {
    isSelected,
    toggleSelect,
    allVisibleSelected,
    someVisibleSelected,
    toggleAllVisible,
} = selection;
const {
    dragIds,
    dropTarget,
    onFolderDragEnter,
    onCardDragStart,
    onCardDragEnd,
} = drag;
const { showThumb, onImgError } = thumbs;

// Headers sort like a file manager: a click sorts by the column, a second
// click flips the direction.
const sortableCols = [
    { key: "original_name", label: "Имя" },
    { key: "created_at", label: "Дата загрузки" },
    { key: "mime_type", label: "Тип" },
    { key: "size", label: "Размер" },
];
function ariaSort(col: string) {
    if (props.sortCol !== col) return "none";
    return props.sortDir === "asc" ? "ascending" : "descending";
}
</script>

<template>
    <div class="ftable-scroll" :class="{ 'is-navigating': navigating }">
        <div
            class="ftable"
            :class="{ 'ftable--select': canSelect }"
            role="table"
            aria-label="Файлы"
        >
            <div class="ftable__row ftable__row--head" role="row">
                <span v-if="canSelect" class="ftable__cell" role="columnheader">
                    <NCheckbox
                        :model-value="allVisibleSelected"
                        :indeterminate="someVisibleSelected"
                        aria-label="Выбрать все на странице"
                        @update:model-value="toggleAllVisible"
                    />
                </span>
                <button
                    v-for="col in sortableCols"
                    :key="col.key"
                    type="button"
                    class="ftable__cell ftable__sort"
                    :class="[
                        'ftable__cell--' + col.key,
                        { 'ftable__sort--on': sortCol === col.key },
                    ]"
                    role="columnheader"
                    :aria-sort="ariaSort(col.key)"
                    @click="emit('sort', col.key)"
                >
                    {{ col.label }}
                    <NIcon
                        v-if="sortCol === col.key"
                        :name="sortDir === 'asc' ? 'arrow-up' : 'arrow-down'"
                        :size="12"
                    />
                </button>
                <span class="ftable__cell" role="columnheader">Разрешение</span>
                <span class="ftable__cell" role="columnheader">Папка</span>
                <span class="ftable__cell" role="columnheader">
                    <span class="sr-only">Действия</span>
                </span>
            </div>
            <div
                v-for="f in folders"
                :key="'folder:' + f.name"
                class="ftable__row ftable__row--folder"
                :class="{ 'ftable__row--drop': dropTarget === f.name }"
                role="row"
                @dblclick="openFolder(f.name)"
                @dragenter.prevent="onFolderDragEnter(f.name)"
                @dragover.prevent
                @drop.prevent="dropOnFolder(f.name)"
            >
                <span v-if="canSelect" class="ftable__cell" role="cell"></span>
                <span class="ftable__cell ftable__name" role="cell">
                    <span class="ftable__thumb ftable__thumb--folder">
                        <FolderGlyph :size="18" />
                    </span>
                    <span class="ftable__namebox">
                        <button
                            type="button"
                            class="ftable__open"
                            :title="'Открыть папку ' + f.name"
                            @click="openFolder(f.name)"
                        >
                            {{ folderLabel(f) }}
                        </button>
                        <span class="ftable__sub">{{ folderSummary(f) }}</span>
                    </span>
                </span>
                <span class="ftable__cell ftable__muted" role="cell">{{
                    f.created_local || "—"
                }}</span>
                <span class="ftable__cell ftable__muted" role="cell"
                    >Папка</span
                >
                <span class="ftable__cell ftable__num" role="cell">{{
                    f.size ? formatBytes(f.size) : "—"
                }}</span>
                <span class="ftable__cell ftable__muted" role="cell">—</span>
                <span class="ftable__cell ftable__muted" role="cell">—</span>
                <span class="ftable__cell ftable__actions" role="cell">
                    <NButton
                        variant="ghost"
                        size="sm"
                        icon="chevron-right"
                        :aria-label="'Открыть папку: ' + f.name"
                        title="Открыть папку"
                        @click.stop="openFolder(f.name)"
                    />
                    <template v-if="can('media.edit')">
                        <NButton
                            variant="ghost"
                            size="sm"
                            icon="edit"
                            :aria-label="'Переименовать папку: ' + f.name"
                            title="Переименовать"
                            @click.stop="askRenameFolder(f.name)"
                        />
                        <NButton
                            variant="ghost"
                            tone="danger"
                            size="sm"
                            icon="trash"
                            :aria-label="'Удалить папку: ' + f.name"
                            title="Удалить папку"
                            @click.stop="askClearFolder(f.name)"
                        />
                    </template>
                </span>
            </div>
            <div
                v-for="m in items"
                :key="m.id"
                class="ftable__row"
                :class="{
                    'ftable__row--selected': isSelected(m.id),
                    'ftable__row--dragging': dragIds.includes(m.id),
                }"
                role="row"
                :draggable="can('media.edit')"
                @dragstart="onCardDragStart($event, m)"
                @dragend="onCardDragEnd"
                @dblclick="openItem(m)"
            >
                <span
                    v-if="canSelect"
                    class="ftable__cell"
                    role="cell"
                    @click.stop
                >
                    <NCheckbox
                        :model-value="isSelected(m.id)"
                        :aria-label="'Выбрать: ' + fileName(m)"
                        @update:model-value="toggleSelect(m.id)"
                    />
                </span>
                <span class="ftable__cell ftable__name" role="cell">
                    <span class="ftable__thumb">
                        <img
                            v-if="showThumb(m)"
                            :src="m.thumb_url || undefined"
                            :style="focalStyle(m)"
                            alt=""
                            loading="lazy"
                            draggable="false"
                            @error="onImgError(m.id)"
                        />
                        <NIcon v-else :name="typeIcon(m)" :size="16" />
                    </span>
                    <button
                        type="button"
                        class="ftable__open"
                        :disabled="!m.url"
                        :title="fileName(m)"
                        @click="openItem(m)"
                    >
                        {{ fileName(m) }}
                    </button>
                </span>
                <span class="ftable__cell ftable__muted" role="cell">{{
                    m.created_local || formatDateTime(m.created_at)
                }}</span>
                <span
                    class="ftable__cell ftable__muted ftable__ellipsis"
                    role="cell"
                    :title="typeLabel(m) + ' ' + typeBadge(m)"
                    >{{ typeLabel(m) }} {{ typeBadge(m) }}</span
                >
                <span class="ftable__cell ftable__num" role="cell">{{
                    formatBytes(m.size)
                }}</span>
                <span class="ftable__cell ftable__muted" role="cell">{{
                    m.width && m.height ? m.width + " × " + m.height : "—"
                }}</span>
                <span
                    class="ftable__cell ftable__muted ftable__ellipsis"
                    role="cell"
                    :title="m.folder || undefined"
                    >{{ m.folder || "—" }}</span
                >
                <span class="ftable__cell ftable__actions" role="cell">
                    <NButton
                        variant="ghost"
                        size="sm"
                        icon="eye"
                        :disabled="!m.url"
                        :aria-label="'Открыть: ' + fileName(m)"
                        :title="openTitle(m)"
                        @click.stop="openItem(m)"
                    />
                    <NButton
                        variant="ghost"
                        size="sm"
                        icon="link"
                        :aria-label="'Скопировать ссылку: ' + fileName(m)"
                        title="Скопировать ссылку"
                        @click.stop="copyUrl(m)"
                    />
                    <NButton
                        variant="ghost"
                        size="sm"
                        icon="edit"
                        :aria-label="editLabel + ': ' + fileName(m)"
                        :title="editLabel"
                        @click.stop="openInfo(m)"
                    />
                    <NButton
                        v-if="can('media.delete')"
                        variant="ghost"
                        tone="danger"
                        size="sm"
                        icon="trash"
                        :aria-label="'Удалить: ' + fileName(m)"
                        title="Удалить"
                        @click.stop="askDelete(m.id)"
                    />
                </span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.is-navigating {
    opacity: 0.55;
    pointer-events: none;
    transition: opacity 0.15s ease;
}
/* A details table like the Windows file manager — one file per row, one kind
   of information per column. */
.ftable-scroll {
    overflow-x: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}
.ftable {
    --ftable-cols: minmax(260px, 3fr) 140px 130px 90px 110px minmax(110px, 1fr)
        136px;
    min-width: 980px;
    font-size: var(--fs);
}
.ftable--select {
    --ftable-cols: 36px minmax(260px, 3fr) 140px 130px 90px 110px
        minmax(110px, 1fr) 136px;
}
.ftable__row {
    display: grid;
    grid-template-columns: var(--ftable-cols);
    align-items: center;
    min-height: 40px;
    border-bottom: 1px solid var(--border);
}
.ftable__row:last-child {
    border-bottom: none;
}
.ftable__row:not(.ftable__row--head):hover {
    background: var(--surface-2);
}
.ftable__row--selected,
.ftable__row--selected:not(.ftable__row--head):hover {
    background: var(--accent-soft);
}
.ftable__row--dragging {
    opacity: 0.5;
}
.ftable__row--drop,
.ftable__row--drop:not(.ftable__row--head):hover {
    border-color: var(--accent);
    box-shadow: inset 0 0 0 2px var(--accent);
    background: var(--accent-soft);
}
.ftable__row--folder {
    cursor: default;
    user-select: none;
}
.ftable__row--head {
    min-height: 36px;
    background: var(--surface-2);
    color: var(--text-3);
    font-size: calc(var(--fs) - 1.5px);
    font-weight: 600;
}
.ftable__cell {
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
    padding: 4px 10px;
}
.ftable__sort {
    border: none;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
    align-self: stretch;
}
.ftable__sort:hover,
.ftable__sort--on {
    color: var(--text);
}
.ftable__sort:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: -2px;
}
.ftable__cell--size {
    justify-content: flex-end;
}
.ftable__name {
    gap: 10px;
}
.ftable__thumb {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    overflow: hidden;
    border-radius: 6px;
    background: var(--surface-3);
    color: var(--text-3);
}
.ftable__thumb--folder {
    color: var(--accent);
    background: var(--accent-soft);
}
.ftable__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.ftable__open {
    min-width: 0;
    overflow: hidden;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--text);
    font: inherit;
    font-weight: 600;
    text-align: left;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: pointer;
}
.ftable__open:hover:not(:disabled) {
    color: var(--accent);
}
.ftable__open:disabled {
    cursor: default;
}
.ftable__muted {
    color: var(--text-2);
    white-space: nowrap;
}
.ftable__ellipsis {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ftable__namebox {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.ftable__sub {
    overflow: hidden;
    font-size: 12px;
    color: var(--text-3);
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ftable__num {
    justify-content: flex-end;
    color: var(--text-2);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.ftable__actions {
    justify-content: flex-end;
    gap: 2px;
    opacity: 0;
    transition: opacity 0.14s ease;
}
.ftable__row:hover .ftable__actions,
.ftable__actions:focus-within {
    opacity: 1;
}
@media (hover: none) {
    .ftable__actions {
        opacity: 1;
    }
}
</style>
