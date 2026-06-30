<script setup>
import NIcon from "../primitives/NIcon.vue";

// NTopbar — application header bar. Props: title, subtitle, collapsible.
// Emits `toggle` when the menu button is clicked (pair with NSidebar collapse).
// Slots: left (after toggle), default (center), right.
// title renders as <h1> by default (this is the page-title bar); set titleTag to
// another element if the page already owns its <h1>. toggleLabel names the button.
defineProps({
    title: { type: String, default: "" },
    subtitle: { type: String, default: "" },
    collapsible: { type: Boolean, default: true },
    titleTag: { type: String, default: "h1" },
    toggleLabel: { type: String, default: "Toggle navigation" },
});
defineEmits(["toggle"]);
</script>

<template>
    <header class="n-tb">
        <button
            v-if="collapsible"
            type="button"
            class="n-tb__toggle"
            :title="toggleLabel"
            :aria-label="toggleLabel"
            @click="$emit('toggle')"
        >
            <NIcon name="list" :size="18" />
        </button>
        <div v-if="title || subtitle" class="n-tb__heading">
            <component :is="titleTag" v-if="title" class="n-tb__title">{{
                title
            }}</component>
            <div v-if="subtitle" class="n-tb__sub">{{ subtitle }}</div>
        </div>
        <slot name="left" />
        <div class="n-tb__spacer"><slot /></div>
        <div class="n-tb__right"><slot name="right" /></div>
    </header>
</template>

<style scoped>
.n-tb {
    position: sticky;
    top: 0;
    z-index: 20;
    height: 68px;
    flex: none;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 0 32px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
}
.n-tb__toggle {
    width: 38px;
    height: 38px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-2);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex: none;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease,
        color 0.15s ease;
}
.n-tb__toggle:hover {
    background: var(--surface-3);
    border-color: var(--text-3);
    color: var(--text);
}
.n-tb__toggle:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-tb__heading {
    line-height: 1.15;
    min-width: 0;
}
.n-tb__title {
    margin: 0;
    font-weight: 800;
    font-size: 16px;
    letter-spacing: -0.02em;
    white-space: nowrap;
    color: var(--text);
}
.n-tb__sub {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text-3);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.n-tb__spacer {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
}
.n-tb__right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: none;
}
</style>
