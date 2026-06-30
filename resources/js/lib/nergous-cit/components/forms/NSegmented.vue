<script setup>
import NIcon from "../primitives/NIcon.vue";
import { useRovingRadio } from "../../composables/useRovingRadio.js";
import { useFormField } from "../../composables/useFormField.js";

// NSegmented — single-choice segmented control. Semantically a radio group,
// visually an inline button group. v-model holds the selected value.
// options: [{ value, label }] or [{ value, icon, label }] — for icon-only options
// `label` is still used as the accessible name. Give the group an `aria-label`.
//
// Keyboard (WAI-ARIA radiogroup) lives in useRovingRadio: ←/↑/→/↓ + Home/End,
// selection follows focus, and the group is a single Tab stop.
const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    options: { type: Array, default: () => [] },
});
const emit = defineEmits(["update:modelValue"]);

const { setBtnRef, tabStop, select, onKeydown } = useRovingRadio(
    () => props.options,
    () => props.modelValue,
    (v) => emit("update:modelValue", v),
);

// Surrounding NFormField (if any) names the group and supplies invalid/required.
const field = useFormField();
</script>

<template>
    <div
        class="n-seg"
        role="radiogroup"
        :aria-labelledby="field?.labelledby.value || undefined"
        :aria-describedby="field?.describedBy.value || undefined"
        :aria-invalid="field?.invalid.value || undefined"
        :aria-required="field?.required.value || undefined"
        @keydown="onKeydown"
    >
        <button
            v-for="(o, i) in options"
            :key="o.value"
            :ref="setBtnRef(i)"
            type="button"
            class="n-seg__btn"
            :class="{ on: modelValue === o.value, icon: !!o.icon }"
            role="radio"
            :aria-checked="modelValue === o.value"
            :aria-label="o.icon ? o.label || undefined : undefined"
            :tabindex="i === tabStop ? 0 : -1"
            @click="select(i)"
        >
            <NIcon v-if="o.icon" :name="o.icon" :size="16" />
            <template v-else>{{ o.label }}</template>
        </button>
    </div>
</template>

<style scoped>
.n-seg {
    display: inline-flex;
    gap: 3px;
    padding: 3px;
    border-radius: 9px;
    background: var(--surface-3);
}
.n-seg__btn {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 32px;
    padding: 0 13px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--text-3);
    font-family: inherit;
    font-weight: 700;
    font-size: 12.5px;
    cursor: pointer;
    transition:
        background-color 0.18s,
        color 0.18s,
        box-shadow 0.18s;
}
.n-seg__btn.icon {
    width: 32px;
    padding: 0;
}
.n-seg__btn:not(.on):hover {
    color: var(--text-2);
}
.n-seg__btn.on {
    background: var(--surface);
    color: var(--text);
    box-shadow: var(--shadow-sm);
}
.n-seg__btn:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
</style>
