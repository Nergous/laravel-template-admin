<script setup lang="ts">
import { computed } from "vue";
import type { PropType } from "vue";
import { NButton, NIcon } from "nergous-ui-vue";
import { can } from "@/lib/can";
import {
    baseName,
    folderSummary,
    parentOf,
    type FolderEntry,
} from "@/lib/media";
import FolderGlyph from "./FolderGlyph";

const props = defineProps({
    /** The open folder; "" — the library root. */
    currentFolder: { type: String, default: "" },
    entry: { type: Object as PropType<FolderEntry>, default: undefined },
});
defineEmits<{
    open: [path: string];
    create: [];
    rename: [];
    clear: [];
}>();

// Breadcrumbs: every folder on the way to the open one.
const crumbs = computed(() => {
    if (!props.currentFolder) return [];
    const parts = props.currentFolder.split("/");
    return parts.map((name, i) => ({
        name,
        path: parts.slice(0, i + 1).join("/"),
    }));
});
</script>

<template>
    <nav class="pathbar" aria-label="Расположение в медиатеке">
        <div class="pathbar__path">
            <NButton
                v-if="currentFolder"
                variant="ghost"
                size="sm"
                icon="chevron-left"
                :title="
                    parentOf(currentFolder)
                        ? 'В папку «' + baseName(parentOf(currentFolder)) + '»'
                        : 'В медиатеку'
                "
                @click="$emit('open', parentOf(currentFolder))"
                >Назад</NButton
            >
            <ol class="crumbs">
                <li class="crumbs__item">
                    <button
                        v-if="currentFolder"
                        type="button"
                        class="crumbs__link"
                        @click="$emit('open', '')"
                    >
                        Медиатека
                    </button>
                    <span v-else class="crumbs__current">Медиатека</span>
                </li>
                <li v-for="(c, i) in crumbs" :key="c.path" class="crumbs__item">
                    <NIcon name="chevron-right" :size="14" />
                    <button
                        v-if="i < crumbs.length - 1"
                        type="button"
                        class="crumbs__link"
                        @click="$emit('open', c.path)"
                    >
                        {{ c.name }}
                    </button>
                    <template v-else>
                        <span class="crumbs__current" aria-current="page">
                            <FolderGlyph :size="16" />
                            {{ c.name }}
                        </span>
                        <span v-if="entry" class="crumbs__count">{{
                            folderSummary(entry)
                        }}</span>
                    </template>
                </li>
            </ol>
        </div>
        <div v-if="can('media.edit')" class="pathbar__actions">
            <NButton
                variant="secondary"
                size="sm"
                icon="plus"
                @click="$emit('create')"
                >Создать папку</NButton
            >
            <template v-if="currentFolder">
                <NButton
                    variant="ghost"
                    size="sm"
                    icon="edit"
                    @click="$emit('rename')"
                    >Переименовать</NButton
                >
                <NButton
                    variant="ghost"
                    size="sm"
                    tone="danger"
                    icon="trash"
                    @click="$emit('clear')"
                    >Удалить папку</NButton
                >
            </template>
        </div>
    </nav>
</template>

<style scoped>
.pathbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    min-height: 36px;
}
.pathbar__path {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.pathbar__actions {
    display: flex;
    align-items: center;
    gap: 6px;
}
.crumbs {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
    min-width: 0;
    font-size: 15px;
}
.crumbs__item {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    color: var(--text-3);
}
.crumbs__link {
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    color: var(--text-2);
    cursor: pointer;
}
.crumbs__link:hover {
    color: var(--accent);
    text-decoration: underline;
}
.crumbs__current {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    font-weight: 600;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.crumbs__current svg {
    flex: none;
    color: var(--accent);
}
.crumbs__count {
    font-size: 12.5px;
    color: var(--text-3);
    white-space: nowrap;
}
</style>
