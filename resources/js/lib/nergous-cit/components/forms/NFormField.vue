<script setup>
// NFormField — labelled form field: label + control slot + error / hint message.
// Associates its message and state with the nested DS control through a
// provide/inject contract (useFormField), wiring aria-describedby / aria-invalid /
// aria-required — and aria-labelledby when the field is not a <label> — so the
// call site does not repeat them. Pass `error` (message string) and optional `hint`.
import { computed, useId } from "vue";
import { provideFormField } from "../../composables/useFormField.js";

const props = defineProps({
    label: { type: String, default: "" },
    error: { type: String, default: "" },
    hint: { type: String, default: "" },
    required: { type: Boolean, default: false },
    // Root tag. Default <label> — clicking the label focuses a single nested
    // control. Use "div" for control groups or button-pickers, where one <label>
    // must not wrap multiple controls.
    tag: { type: String, default: "label" },
    // Explicit id for the label element. Lets a custom grouped control in the slot
    // (e.g. a role="group" of buttons) point its aria-labelledby at the visible
    // label instead of repeating the text. Defaults to an auto-generated id.
    labelId: { type: String, default: "" },
});

const autoLabelId = useId();
const labelId = computed(() => props.labelId || autoLabelId);
const errorId = useId();
const hintId = useId();

provideFormField({
    // Only when the field is not a <label> (no implicit wrapping association).
    labelledby: computed(() =>
        props.tag !== "label" && props.label ? labelId.value : undefined,
    ),
    describedBy: computed(() =>
        props.error ? errorId : props.hint ? hintId : undefined,
    ),
    invalid: computed(() => !!props.error),
    required: computed(() => props.required),
});
</script>

<template>
    <component :is="tag" class="n-field">
        <span v-if="label" :id="labelId" class="n-field__label">
            {{ label }}
            <span v-if="required" class="n-field__req" aria-hidden="true"
                >*</span
            >
        </span>
        <slot />
        <span v-if="error" :id="errorId" class="n-field__error">{{
            error
        }}</span>
        <span v-else-if="hint" :id="hintId" class="n-field__hint">{{
            hint
        }}</span>
    </component>
</template>

<style scoped>
.n-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.n-field__label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-2);
}
.n-field__req {
    color: var(--danger);
    margin-left: 2px;
}
.n-field__error {
    font-size: 12px;
    color: var(--danger);
}
.n-field__hint {
    font-size: 12px;
    color: var(--text-3);
}
</style>
