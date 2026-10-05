<script setup lang="ts">
import { ref } from "vue";
import type { PropType } from "vue";
import { NButton, NIcon } from "nergous-ui-vue";
import MediaPicker from "@/admin/components/MediaPicker.vue";
import type { MediaItem, MediaRef } from "@/admin/types";
import { formatBytes } from "@/lib/format";

const props = defineProps({
    modelValue: {
        type: Object as PropType<MediaRef | null>,
        default: null,
    },
    /** Library filter: image or document. */
    type: { type: String, default: "image" },
    title: { type: String, default: "Выбрать файл" },
});
const emit = defineEmits<{ "update:modelValue": [value: MediaRef | null] }>();

const open = ref(false);

function onSelect(items: MediaItem[]) {
    const item = items[0];
    if (!item) return;
    emit("update:modelValue", {
        id: item.id,
        type: item.type,
        url: item.url,
        thumb_url: item.thumb_url,
        original_name: item.original_name,
        size: item.size,
        alt: item.alt,
    });
}
</script>

<template>
    <div class="media-field">
        <div v-if="modelValue" class="media-field__item">
            <img
                v-if="modelValue.type === 'image' || type === 'image'"
                :src="modelValue.thumb_url || modelValue.url"
                :alt="modelValue.alt || ''"
                class="media-field__thumb"
                loading="lazy"
            />
            <span v-else class="media-field__glyph"
                ><NIcon name="copy" :size="22"
            /></span>
            <span class="media-field__meta">
                <a
                    :href="modelValue.url"
                    target="_blank"
                    rel="noopener"
                    class="media-field__name"
                    >{{ modelValue.original_name || modelValue.url }}</a
                >
                <span v-if="modelValue.size" class="media-field__size">{{
                    formatBytes(modelValue.size)
                }}</span>
            </span>
        </div>
        <div class="media-field__actions">
            <NButton
                size="sm"
                variant="secondary"
                icon="asset"
                type="button"
                @click="open = true"
            >
                {{ modelValue ? "Заменить" : title }}
            </NButton>
            <NButton
                v-if="modelValue"
                size="sm"
                variant="ghost"
                tone="danger"
                icon="x"
                type="button"
                @click="emit('update:modelValue', null)"
                >Убрать</NButton
            >
        </div>
        <MediaPicker
            v-model="open"
            :max="1"
            :type="type"
            :preselected="modelValue ? [modelValue as MediaItem] : []"
            :title="title"
            confirm-label="Выбрать"
            @select="onSelect"
        />
    </div>
</template>

<style scoped>
.media-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.media-field__item {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.media-field__thumb {
    width: 96px;
    height: 64px;
    object-fit: cover;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: var(--surface-3);
    flex-shrink: 0;
}
.media-field__glyph {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: var(--radius-sm);
    background: var(--surface-3);
    color: var(--text-3);
    flex-shrink: 0;
}
.media-field__meta {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.media-field__name {
    color: var(--text);
    font-size: 13px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.media-field__size {
    color: var(--text-3);
    font-size: 12px;
}
.media-field__actions {
    display: flex;
    gap: 6px;
}
</style>
