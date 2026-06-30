// Contract between NFormField and the form controls nested in its slot. The
// field provides ARIA wiring — the described-by message id, invalid/required
// state, and a label id when the field is not a <label> — and controls inject it
// to set the matching aria-* attributes without the call site repeating them.
// useFormField() returns null when a control is used outside any NFormField.
import { provide, inject } from "vue";

const FORM_FIELD_KEY = Symbol("n-form-field");

export function provideFormField(ctx) {
    provide(FORM_FIELD_KEY, ctx);
}

export function useFormField() {
    return inject(FORM_FIELD_KEY, null);
}
