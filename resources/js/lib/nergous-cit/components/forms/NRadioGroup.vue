<script setup>
import { useRovingRadio } from "../../composables/useRovingRadio.js";
import { useFormField } from "../../composables/useFormField.js";

// NRadioGroup — single-choice radio buttons. v-model holds the selected value.
// options: [{ value, label }]. Give the group an `aria-label`.
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
        class="n-radio-group"
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
            class="n-radio"
            role="radio"
            :aria-checked="modelValue === o.value"
            :tabindex="i === tabStop ? 0 : -1"
            @click="select(i)"
        >
            <span class="n-radio__ring" :class="{ on: modelValue === o.value }">
                <span v-if="modelValue === o.value" class="n-radio__dot" />
            </span>
            {{ o.label }}
        </button>
    </div>
</template>

<style scoped>
.n-radio-group {
    display: inline-flex;
    gap: var(--sp-4);
    flex-wrap: wrap;
}
.n-radio {
    display: inline-flex;
    align-items: center;
    gap: var(--sp-2);
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    font-family: inherit;
    font-weight: 600;
    font-size: 14px;
    color: var(--text-2);
    cursor: pointer;
    padding: 0;
}
.n-radio:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 3px;
}
.n-radio__ring {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 1.6px solid var(--border-2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: border-color 0.15s;
}
.n-radio__ring.on {
    border-color: var(--accent);
}
.n-radio__dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--accent);
}
</style>
