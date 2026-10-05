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

defineProps({
    folders: { type: Array as PropType<FolderEntry[]>, default: () => [] },
    items: { type: Array as PropType<MediaItem[]>, default: () => [] },
});

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
const { isSelected, toggleSelect } = selection;
const {
    dragIds,
    dropTarget,
    onFolderDragEnter,
    onFolderDragLeave,
    onCardDragStart,
    onCardDragEnd,
} = drag;
const { showThumb, onImgError } = thumbs;
</script>

<template>
    <div class="media media--grid" :class="{ 'is-navigating': navigating }">
        <div
            v-for="f in folders"
            :key="'folder:' + f.name"
            class="mcard mcard--folder"
            :class="{ 'mcard--drop': dropTarget === f.name }"
            @dragenter.prevent="onFolderDragEnter(f.name)"
            @dragover.prevent
            @dragleave.self="onFolderDragLeave(f.name)"
            @drop.prevent="dropOnFolder(f.name)"
        >
            <div class="mcard__preview mcard__preview--folder">
                <button
                    type="button"
                    class="mcard__open mcard__open--clickable"
                    :aria-label="'Открыть папку: ' + f.name"
                    @click="openFolder(f.name)"
                >
                    <span class="mcard__glyph mcard__glyph--folder">
                        <FolderGlyph :size="56" />
                    </span>
                </button>
                <span class="mcard__badge">Папка</span>
                <div v-if="can('media.edit')" class="mcard__actions">
                    <NButton
                        class="mcard__act"
                        variant="ghost"
                        tone="accent"
                        size="sm"
                        icon="edit"
                        :aria-label="'Переименовать папку: ' + f.name"
                        title="Переименовать"
                        @click.stop="askRenameFolder(f.name)"
                    />
                    <NButton
                        class="mcard__act"
                        variant="ghost"
                        tone="danger"
                        size="sm"
                        icon="trash"
                        :aria-label="'Удалить папку: ' + f.name"
                        title="Удалить папку"
                        @click.stop="askClearFolder(f.name)"
                    />
                </div>
            </div>
            <div class="mcard__foot">
                <div class="mcard__name" :title="f.name">
                    {{ folderLabel(f) }}
                </div>
                <div class="mcard__meta">
                    <span>{{ folderSummary(f) }}</span>
                    <span v-if="f.size">{{ formatBytes(f.size) }}</span>
                </div>
                <div class="mcard__date">
                    {{ f.created_local || "—" }}
                </div>
            </div>
        </div>
        <div
            v-for="m in items"
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
                            : 'Открыть файл в новой вкладке: ') + fileName(m)
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
                        :alt="m.alt || fileName(m)"
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
                        :aria-label="'Выбрать: ' + fileName(m)"
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
                        :disabled="!m.url"
                        :aria-label="'Открыть: ' + fileName(m)"
                        :title="openTitle(m)"
                        @click.stop="openItem(m)"
                    />
                    <NButton
                        class="mcard__act"
                        variant="ghost"
                        tone="accent"
                        size="sm"
                        icon="link"
                        :aria-label="'Скопировать ссылку: ' + fileName(m)"
                        title="Скопировать ссылку"
                        @click.stop="copyUrl(m)"
                    />
                    <NButton
                        class="mcard__act"
                        variant="ghost"
                        tone="accent"
                        size="sm"
                        icon="edit"
                        :aria-label="editLabel + ': ' + fileName(m)"
                        :title="editLabel"
                        @click.stop="openInfo(m)"
                    />
                    <NButton
                        v-if="can('media.delete')"
                        class="mcard__act"
                        variant="ghost"
                        tone="danger"
                        size="sm"
                        icon="trash"
                        :aria-label="'Удалить: ' + fileName(m)"
                        @click.stop="askDelete(m.id)"
                    />
                </div>
            </div>
            <div class="mcard__foot">
                <div class="mcard__name">
                    {{ fileName(m) }}
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
                    {{ m.created_local || formatDateTime(m.created_at) }}
                </div>
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
.media--grid {
    display: grid;
    /* The minimum column width follows the base font size (--fs):
       a 186px base + --fs (200px at the default size). */
    grid-template-columns: repeat(
        auto-fill,
        minmax(calc(186px + var(--fs)), 1fr)
    );
    gap: 16px;
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
.mcard--dragging {
    opacity: 0.5;
}
.mcard--drop {
    border-color: var(--accent);
    box-shadow: inset 0 0 0 2px var(--accent);
    background: var(--accent-soft);
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
.mcard__preview--folder {
    background: var(--accent-soft);
    background-image: none;
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
.mcard__glyph--folder {
    color: var(--accent);
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
    /* Row padding comes from the --row-pad token. */
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
    /* Slightly smaller than the name. */
    font-size: calc(var(--fs) - 2px);
    color: var(--text-3);
    display: flex;
    justify-content: space-between;
    margin-top: 4px;
}
.mcard__folder {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mcard__date {
    margin-top: 2px;
    font-size: calc(var(--fs) - 2.5px);
    color: var(--text-3);
}
</style>
