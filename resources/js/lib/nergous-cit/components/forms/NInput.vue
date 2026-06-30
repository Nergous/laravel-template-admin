<script setup>
import { computed, ref } from "vue";
import NIcon from "../primitives/NIcon.vue";
import { useFormField } from "../../composables/useFormField.js";

// NInput — single-line text field. v-model holds the value.
// Props: type, placeholder, icon (leading), error (red invalid state), disabled,
//        size (`sm` | `md` | `lg`; `md` = default density, unchanged).
// A `type="password"` field gets a trailing show/hide toggle automatically;
// pass revealLabel / hideLabel to localize its accessible name.
// Extra fallthrough attrs (autocomplete, name, id, required, aria-*) are
// forwarded to the inner <input>, not the wrapper — see inheritAttrs:false.
const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    type: { type: String, default: "text" },
    placeholder: { type: String, default: "" },
    icon: { type: String, default: "" },
    error: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    size: { type: String, default: "md" },
    // Accessible names for the password reveal toggle (English defaults; app localizes).
    revealLabel: { type: String, default: "Show password" },
    hideLabel: { type: String, default: "Hide password" },
    // v-model modifiers (.number / .trim) arrive here for custom components.
    modelModifiers: { type: Object, default: () => ({}) },
});
const emit = defineEmits(["update:modelValue"]);

// Surrounding NFormField (if any) supplies invalid/required/described-by/label.
const field = useFormField();
const invalid = computed(() => props.error || !!field?.invalid.value);

// Password reveal: a trailing eye toggle swaps the input type to "text" while held on.
const revealed = ref(false);
const isPassword = computed(() => props.type === "password");
const effectiveType = computed(() =>
    isPassword.value && revealed.value ? "text" : props.type,
);

// Native <input>.value is ALWAYS a string. Coerce on the way out so v-model
// holds the right JS type: number/range -> Number; everything else (incl. date,
// which emits an ISO "2026-06-24" string) stays a string. Mirrors Vue's
// built-in .number — empty input stays "" (Laravel turns it into null).
function onInput(e) {
    let value = e.target.value;
    if (props.modelModifiers.trim) value = value.trim();
    if (
        props.modelModifiers.number ||
        props.type === "number" ||
        props.type === "range"
    ) {
        const n = parseFloat(value);
        value = isNaN(n) ? value : n;
    }
    emit("update:modelValue", value);
}
</script>

<script>
export default { inheritAttrs: false };
</script>

<template>
    <div
        class="n-input-wrap"
        :class="{ 'has-icon': icon, 'has-reveal': isPassword }"
    >
        <span v-if="icon" class="n-input__icon"
            ><NIcon :name="icon" :size="16"
        /></span>
        <input
            class="n-input"
            :class="['n-input--' + size, { 'n-input--error': invalid }]"
            :type="effectiveType"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            v-bind="$attrs"
            :aria-invalid="invalid || undefined"
            :aria-required="field?.required.value || undefined"
            :aria-labelledby="field?.labelledby.value || undefined"
            :aria-describedby="field?.describedBy.value || undefined"
            @input="onInput"
        />
        <button
            v-if="isPassword"
            type="button"
            class="n-input__reveal"
            :aria-label="revealed ? hideLabel : revealLabel"
            :aria-pressed="revealed"
            :disabled="disabled"
            @click="revealed = !revealed"
        >
            <NIcon :name="revealed ? 'eye-off' : 'eye'" :size="16" />
        </button>
    </div>
</template>

<style scoped>
.n-input-wrap {
    position: relative;
    display: block;
}
.n-input__icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-3);
    display: flex;
    pointer-events: none;
}
.n-input {
    width: 100%;
    height: var(--control-h);
    padding: 0 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text);
    font-family: inherit;
    font-size: var(--fs);
    outline: none;
    transition: 0.14s;
}
.has-icon .n-input {
    padding-left: 38px;
}
.has-reveal .n-input {
    padding-right: 40px;
}
.n-input__reveal {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text-3);
    cursor: pointer;
}
.n-input__reveal:hover:not(:disabled) {
    color: var(--text-2);
}
.n-input__reveal:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 1px;
}
.n-input__reveal:disabled {
    cursor: not-allowed;
}
.n-input--lg {
    height: 44px;
    border-radius: 11px;
}
.n-input--sm {
    height: 32px;
}
.n-input::placeholder {
    color: var(--text-3);
}
.n-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent);
}
.n-input--error {
    border-color: var(--danger);
    border-width: 1.5px;
}
.n-input:disabled {
    background: var(--surface-3);
    color: var(--text-3);
    cursor: not-allowed;
}
</style>
