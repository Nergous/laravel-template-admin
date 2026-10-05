<script setup lang="ts">
import { NButton, NCheckbox } from "nergous-ui-vue";
import { can } from "@/lib/can";
import { pluralize } from "@/lib/format";
import { useMediaLibrary } from "./library";

defineProps({
    /** Files of the list for the current filters, on every page. */
    total: { type: Number, required: true },
    /** The grid has no header row: offer "select the page" above it. */
    showPageToggle: { type: Boolean, default: false },
});
defineEmits<{ move: []; delete: [] }>();

const { canSelect, selection } = useMediaLibrary();
const {
    allMatching,
    selectedCount,
    canSelectAllMatching,
    selectAllMatching,
    clearSelection,
    allVisibleSelected,
    someVisibleSelected,
    toggleAllVisible,
} = selection;
</script>

<template>
    <div v-if="canSelect && showPageToggle" class="subbar">
        <NCheckbox
            :model-value="allVisibleSelected"
            :indeterminate="someVisibleSelected"
            @update:model-value="toggleAllVisible"
        >
            Выбрать все на странице
        </NCheckbox>
        <span class="subbar__total"
            >Всего: {{ total }}
            {{ pluralize(total, "файл", "файла", "файлов") }}</span
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
            <template v-if="allMatching"> — все по текущему фильтру</template>
        </span>
        <div class="selbar__actions">
            <NButton
                v-if="canSelectAllMatching"
                variant="ghost"
                size="sm"
                @click="selectAllMatching"
            >
                Выбрать все {{ total }}
            </NButton>
            <NButton variant="ghost" size="sm" @click="clearSelection">
                Снять выделение
            </NButton>
            <NButton
                v-if="can('media.edit')"
                variant="secondary"
                size="sm"
                icon="layers"
                @click="$emit('move')"
            >
                Переместить в папку
            </NButton>
            <NButton
                v-if="can('media.delete')"
                variant="danger"
                size="sm"
                icon="trash"
                @click="$emit('delete')"
            >
                Удалить выбранные
            </NButton>
        </div>
    </div>
</template>

<style scoped>
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
</style>
