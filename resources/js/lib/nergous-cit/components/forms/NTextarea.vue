<script setup>
// NTextarea — multi-line text field. v-model holds the value; rows sets the height.
// Mirrors NInput: `error` paints the invalid state (red border + aria-invalid).
// Fallthrough attrs (name, id, maxlength, required, aria-*) land on the inner
// <textarea> automatically — it is the single root element, no wrapper.
import { computed } from "vue";
import { useFormField } from "../../composables/useFormField.js";

const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    placeholder: { type: String, default: "" },
    rows: { type: [Number, String], default: 3 },
    error: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    // v-model.trim modifier arrives here for custom components.
    modelModifiers: { type: Object, default: () => ({}) },
});
const emit = defineEmits(["update:modelValue"]);

// Surrounding NFormField (if any) supplies invalid/required/described-by/label.
const field = useFormField();
const invalid = computed(() => props.error || !!field?.invalid.value);

function onInput(e) {
    const value = props.modelModifiers.trim
        ? e.target.value.trim()
        : e.target.value;
    emit("update:modelValue", value);
}
</script>

<template>
    <textarea
        class="n-textarea"
        :class="{ 'n-textarea--error': invalid }"
        :rows="rows"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-required="field?.required.value || undefined"
        :aria-labelledby="field?.labelledby.value || undefined"
        :aria-describedby="field?.describedBy.value || undefined"
        @input="onInput"
    />
</template>

<style scoped>
.n-textarea {
    width: 100%;
    min-height: 74px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text);
    font-family: inherit;
    font-size: var(--fs);
    line-height: 1.5;
    outline: none;
    resize: vertical;
    transition: 0.14s;
}
.n-textarea::placeholder {
    color: var(--text-3);
}
.n-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent);
}
.n-textarea--error {
    border-color: var(--danger);
    border-width: 1.5px;
}
.n-textarea:disabled {
    background: var(--surface-3);
    color: var(--text-3);
    cursor: not-allowed;
}
</style>
