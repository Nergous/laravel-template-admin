<script setup>
/**
 * NButton — primary clickable button. The default slot is the button label.
 */
import { computed, useSlots, useAttrs } from "vue";
import NIcon from "../primitives/NIcon.vue";

const props = defineProps({
    /** Visual style: `primary` | `secondary` | `ghost` | `danger`. */
    variant: { type: String, default: "primary" },
    /** Size: `sm` | `md` | `lg`. `md` tracks density (`--control-h`). */
    size: { type: String, default: "md" },
    /** Hover-tone for ghost/icon buttons: `''` | `accent` | `danger`. */
    tone: { type: String, default: "" },
    /** Leading icon name (see NIcon); empty = no icon. */
    icon: { type: String, default: "" },
    /** Show a spinner and ignore clicks while busy. */
    loading: { type: Boolean, default: false },
    /** Disable interaction. */
    disabled: { type: Boolean, default: false },
    /** Stretch to the full container width. */
    block: { type: Boolean, default: false },
    /** Native button type. Defaults to `button` so it never submits a form by
     *  accident; pass `submit` for a form's submit button. Ignored when `as`
     *  is not a `<button>`. */
    type: { type: String, default: "button" },
    /** Polymorphic root: render as a different element/component (e.g. the Inertia
     *  `<Link>`, or `"a"`) instead of `<button>`. Keeps the button styling while
     *  yielding correct link semantics — avoids nesting a `<button>` inside an
     *  `<a>`. Non-button roots ignore `type`; a disabled non-button gets
     *  `aria-disabled` instead of the `disabled` attribute. */
    as: { type: [String, Object, Function], default: "button" },
});

const slots = useSlots();
const attrs = useAttrs();
// icon-only (square) button: has an icon and no default (text) slot.
const iconOnly = computed(() => !!props.icon && !slots.default);
// Native <button> root vs a polymorphic one (link/component).
const isButton = computed(() => props.as === "button");

// An icon-only button has no visible text — it needs an accessible name.
// aria-label / aria-labelledby fall through to <button>; warn in dev if missing.
if (
    import.meta.env.DEV &&
    iconOnly.value &&
    !attrs["aria-label"] &&
    !attrs["aria-labelledby"]
) {
    console.warn(
        `[NButton] icon-only button ("${props.icon}") has no accessible name — add aria-label.`,
    );
}
</script>

<template>
    <component
        :is="as"
        class="n-btn"
        :type="isButton ? type : undefined"
        :class="[
            'n-btn--' + variant,
            'n-btn--' + size,
            { 'n-btn--block': block, 'n-btn--icon': iconOnly },
            tone ? 'n-btn--tone-' + tone : '',
        ]"
        :disabled="isButton ? disabled || loading : undefined"
        :aria-disabled="!isButton && (disabled || loading) ? true : undefined"
        :aria-busy="loading || undefined"
    >
        <span v-if="loading" class="n-btn__spin" />
        <NIcon v-else-if="icon" :name="icon" :size="size === 'lg' ? 18 : 16" />
        <slot />
    </component>
</template>

<style scoped>
.n-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: none;
    border-radius: var(--radius-md);
    font-family: inherit;
    font-weight: 700;
    cursor: pointer;
    transition: 0.14s;
    white-space: nowrap;
    text-decoration: none;
    outline: none;
}
.n-btn:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-btn--block {
    display: flex;
    width: 100%;
}
.n-btn--sm {
    height: 32px;
    padding: 0 13px;
    font-size: 12.5px;
    border-radius: var(--radius-sm);
}
.n-btn--md {
    height: var(--control-h);
    padding: 0 16px;
    font-size: var(--fs);
}
.n-btn--lg {
    height: 46px;
    padding: 0 20px;
    font-size: 14.5px;
    border-radius: 11px;
}
/* icon-only — square sized to the control height */
.n-btn--icon {
    padding: 0;
}
.n-btn--icon.n-btn--sm {
    width: 32px;
}
.n-btn--icon.n-btn--md {
    width: var(--control-h);
}
.n-btn--icon.n-btn--lg {
    width: 46px;
}
.n-btn--primary {
    background: var(--accent);
    color: var(--accent-on);
    box-shadow: 0 2px 8px -2px var(--accent);
}
.n-btn--primary:hover:not(:disabled) {
    background: var(--accent-h);
}
.n-btn--secondary {
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border);
}
.n-btn--secondary:hover:not(:disabled) {
    background: var(--surface-3);
    border-color: var(--text-3);
}
.n-btn--ghost {
    background: transparent;
    color: var(--text-2);
}
.n-btn--ghost:hover:not(:disabled) {
    background: var(--surface-3);
    color: var(--text);
}
.n-btn--ghost.n-btn--tone-accent:hover:not(:disabled) {
    background: var(--surface-3);
    color: var(--accent);
}
.n-btn--ghost.n-btn--tone-danger:hover:not(:disabled) {
    background: var(--danger-bg);
    color: var(--danger);
}
.n-btn--danger {
    background: var(--danger-bg);
    color: var(--danger);
}
.n-btn:disabled {
    background: var(--surface-3);
    color: var(--text-3);
    cursor: not-allowed;
    box-shadow: none;
}
.n-btn__spin {
    width: 14px;
    height: 14px;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: n-spin 0.7s linear infinite;
    opacity: 0.55;
}
@keyframes n-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
