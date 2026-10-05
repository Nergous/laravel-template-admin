<script setup lang="ts">
import type { PropType } from "vue";

defineProps({
    folders: { type: Array as PropType<string[]>, default: () => [] },
});
/** Folder path typed in the neighbouring field; a chip fills it in. */
const model = defineModel<string>({ required: true });
</script>

<template>
    <div v-if="folders.length" class="folder-chips">
        <button
            v-for="f in folders"
            :key="f"
            type="button"
            class="folder-chip"
            :class="{ 'folder-chip--on': model.trim() === f }"
            @click="model = f"
        >
            {{ f }}
        </button>
    </div>
</template>

<style scoped>
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
</style>
