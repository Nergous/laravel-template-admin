<script setup>
// NSwitch — on/off toggle. v-model holds the boolean state.
// It renders no text, so it needs an external accessible name: pass `aria-label`,
// or `aria-labelledby` pointing at a visible caption. Such attrs fall through to
// the inner <button> (it is the single root element).
import { useAttrs } from "vue";

defineProps({
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
defineEmits(["update:modelValue"]);

// A switch with no visible label must be named for assistive tech; warn in dev.
const attrs = useAttrs();
if (import.meta.env.DEV && !attrs["aria-label"] && !attrs["aria-labelledby"]) {
    console.warn(
        "[NSwitch] toggle has no accessible name — add aria-label or aria-labelledby.",
    );
}
</script>

<template>
    <button
        type="button"
        class="n-switch"
        :class="{ on: modelValue }"
        :disabled="disabled"
        role="switch"
        :aria-checked="modelValue"
        @click="$emit('update:modelValue', !modelValue)"
    >
        <span class="n-switch__knob" />
    </button>
</template>

<style scoped>
.n-switch {
    width: 40px;
    height: 24px;
    border-radius: 999px;
    border: none;
    padding: 2px;
    display: inline-flex;
    align-items: center;
    background: var(--border-2);
    transition: background-color 0.2s;
    cursor: pointer;
    flex: none;
}
.n-switch.on {
    background: var(--accent);
}
.n-switch:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-switch__knob {
    width: 19px;
    height: 19px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    transition: transform 0.2s cubic-bezier(0.4, 1.3, 0.5, 1);
    transform: translateX(0);
}
.n-switch.on .n-switch__knob {
    transform: translateX(17px);
}
.n-switch:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
