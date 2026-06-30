<script setup>
import { useSlots } from "vue";
import NIcon from "../primitives/NIcon.vue";

// NCheckbox — boolean checkbox. v-model holds the checked state (Boolean).
// Default slot is the label shown next to the box.
// `indeterminate` shows the "mixed" state (a dash) for partial select-all cases;
// clicking it checks the box, like a native indeterminate checkbox.
// `ariaLabel` names a label-less checkbox (e.g. a table select-all/select-row).
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    indeterminate: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    ariaLabel: { type: String, default: "" },
});
defineEmits(["update:modelValue"]);

// Surface a missing accessible name in dev (no visible label and no aria-label).
const slots = useSlots();
if (import.meta.env.DEV && !slots.default && !props.ariaLabel) {
    console.warn(
        "[NCheckbox] no visible label and no aria-label — provide one for an accessible name.",
    );
}
</script>

<template>
    <label class="n-check" :class="{ disabled }">
        <button
            type="button"
            class="n-check__box"
            :class="{ on: modelValue || indeterminate }"
            role="checkbox"
            :aria-checked="indeterminate ? 'mixed' : modelValue"
            :aria-label="ariaLabel || undefined"
            :disabled="disabled"
            @click="$emit('update:modelValue', !modelValue)"
        >
            <NIcon
                class="n-check__tick"
                :name="indeterminate ? 'minus' : 'check'"
                :size="13"
            />
        </button>
        <span v-if="$slots.default" class="n-check__label"><slot /></span>
    </label>
</template>

<style scoped>
.n-check {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    cursor: pointer;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-2);
}
.n-check__box {
    position: relative;
    width: 20px;
    height: 20px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1.6px solid var(--border-2);
    background: var(--surface);
    color: #fff;
    transition:
        background-color 0.15s,
        border-color 0.15s;
    cursor: pointer;
    padding: 0;
    outline: none;
}
/* Expand the hit area to 24×24 (WCAG 2.5.8) without enlarging the 20px visual,
   so a label-less box (table select-all/row) still meets the target minimum. */
.n-check__box::before {
    content: "";
    position: absolute;
    inset: -2px;
}
.n-check__box.on {
    border-color: var(--accent);
    background: var(--accent);
}
.n-check__box:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-check__tick {
    transform: scale(0);
    transition: transform 0.2s cubic-bezier(0.5, 1.6, 0.5, 1);
}
.n-check__box.on .n-check__tick {
    transform: scale(1);
}
.n-check.disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
</style>
