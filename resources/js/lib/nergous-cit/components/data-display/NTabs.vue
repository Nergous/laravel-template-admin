<script setup>
import { computed, useId } from "vue";
import { useRovingRadio } from "../../composables/useRovingRadio.js";

// NTabs — horizontal WAI-ARIA tablist. v-model holds the active tab value.
// tabs: [{ value, label }]. Roving tabindex + ←/↑/→/↓ + Home/End with selection
// following focus (shared with NSegmented/NRadioGroup via useRovingRadio).
// Pass an `aria-label` to name the tablist. Panels are rendered by the consumer;
// to link them, pass a stable `id-base` and wire each panel as:
//   id="${idBase}-panel-${value}" role="tabpanel" aria-labelledby="${idBase}-${value}"
// Each tab already points aria-controls at that panel id. Without `id-base` the
// ids are auto-generated and panels can't be linked deterministically.
const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    tabs: { type: Array, default: () => [] },
    idBase: { type: String, default: "" },
});
const emit = defineEmits(["update:modelValue"]);

const generatedId = useId();
const baseId = computed(() => props.idBase || generatedId);
const tabId = (value) => `${baseId.value}-${value}`;
const panelId = (value) => `${baseId.value}-panel-${value}`;

const { setBtnRef, tabStop, select, onKeydown } = useRovingRadio(
    () => props.tabs,
    () => props.modelValue,
    (v) => emit("update:modelValue", v),
);
</script>

<template>
    <div class="n-tabs" role="tablist" @keydown="onKeydown">
        <button
            v-for="(t, i) in tabs"
            :key="t.value"
            :id="tabId(t.value)"
            :ref="setBtnRef(i)"
            type="button"
            role="tab"
            class="n-tab"
            :class="{ on: modelValue === t.value }"
            :aria-selected="modelValue === t.value"
            :aria-controls="idBase ? panelId(t.value) : undefined"
            :tabindex="i === tabStop ? 0 : -1"
            @click="select(i)"
        >
            {{ t.label }}
        </button>
    </div>
</template>

<style scoped>
.n-tabs {
    display: flex;
    border-bottom: 1px solid var(--border);
}
.n-tab {
    padding: 11px 2px;
    margin-right: 24px;
    margin-bottom: -1px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--text-3);
    font-family: inherit;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
    transition:
        color 0.15s,
        border-color 0.15s;
}
.n-tab:not(.on):hover {
    color: var(--text-2);
}
.n-tab.on {
    color: var(--text);
    border-bottom-color: var(--accent);
}
.n-tab:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
    border-radius: 4px;
}
</style>
