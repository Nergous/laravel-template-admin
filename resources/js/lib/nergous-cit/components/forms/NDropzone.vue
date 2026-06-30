<script setup>
import { ref } from "vue";
import NIcon from "../primitives/NIcon.vue";

// NDropzone — drag-and-drop / click file picker. Emits `files` (Array<File>) on
// drop or pick. Visible text is locale-agnostic: neutral English defaults,
// the app passes localized labels via props (title/browseLabel/orLabel/hint).
//
// The root is a <label> wrapping a visually-hidden but focusable file input, so
// the whole area is clickable AND keyboard-operable (Tab to focus, Enter/Space
// to open the dialog) — no manual click forwarding needed.
defineProps({
    accept: { type: String, default: "" },
    multiple: { type: Boolean, default: true },
    title: { type: String, default: "Drop files here" },
    orLabel: { type: String, default: "or" },
    browseLabel: { type: String, default: "browse your device" },
    hint: { type: String, default: "" },
});
const emit = defineEmits(["files"]);

const over = ref(false);

function onDrop(e) {
    over.value = false;
    const files = Array.from(e.dataTransfer?.files || []);
    if (files.length) emit("files", files);
}
// dragleave bubbles when moving onto child elements; only clear the highlight
// when the pointer actually leaves the dropzone.
function onDragLeave(e) {
    if (!e.currentTarget.contains(e.relatedTarget)) over.value = false;
}
function onPick(e) {
    const files = Array.from(e.target.files || []);
    if (files.length) emit("files", files);
    e.target.value = "";
}
</script>

<template>
    <label
        class="n-dz"
        :class="{ over }"
        @dragover.prevent="over = true"
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
    >
        <input
            class="n-dz__input"
            type="file"
            :accept="accept"
            :multiple="multiple"
            @change="onPick"
        />
        <div class="n-dz__icon"><NIcon name="upload" :size="24" /></div>
        <div class="n-dz__title">{{ title }}</div>
        <div class="n-dz__hint">
            {{ orLabel }}
            <span class="n-dz__link">{{ browseLabel }}</span>
            <template v-if="hint"> · {{ hint }}</template>
        </div>
    </label>
</template>

<style scoped>
.n-dz {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-align: center;
    padding: 24px;
    border-radius: var(--radius-lg);
    border: 2px dashed var(--border-2);
    background: var(--surface-2);
    cursor: pointer;
    transition:
        border-color 0.15s ease,
        background-color 0.15s ease;
}
.n-dz.over {
    border-color: var(--accent);
    background: var(--accent-soft);
}
.n-dz:focus-within {
    border-color: var(--accent);
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
/* visually hidden, but still focusable & in the tab order */
.n-dz__input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
.n-dz__icon {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-lg);
    background: var(--accent-soft);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
}
.n-dz__title {
    font-weight: 700;
    font-size: 15.5px;
    color: var(--text);
}
.n-dz__hint {
    font-size: 13px;
    color: var(--text-3);
}
.n-dz__link {
    color: var(--accent);
    font-weight: 700;
}
</style>
