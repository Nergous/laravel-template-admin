<script setup>
import { useId } from "vue";

// NTooltip — CSS-only hover/focus tooltip. Default slot is the trigger.
// Props: content (text), placement (top | bottom | left | right).
// Best for NON-interactive triggers (icon/text): the wrapper itself is the
// focusable, aria-describedby element. For an interactive trigger (button/link)
// the wrapper adds a second tab stop — use a ref/directive approach instead.
// Being CSS-positioned, the bubble can be clipped by an overflow:hidden ancestor;
// reach for NDropdown/NModal when the trigger lives inside a clipped box.
const props = defineProps({
    content: { type: String, default: "" },
    placement: {
        type: String,
        default: "top",
        validator: (v) => ["top", "bottom", "left", "right"].includes(v),
    }, // top | bottom | left | right
});
const tipId = useId();
</script>

<template>
    <span
        class="n-tip"
        :class="'n-tip--' + placement"
        :tabindex="content ? 0 : undefined"
        :aria-describedby="content ? tipId : undefined"
    >
        <slot />
        <span v-if="content" :id="tipId" class="n-tip__bubble" role="tooltip">{{
            content
        }}</span>
    </span>
</template>

<style scoped>
.n-tip {
    position: relative;
    display: inline-flex;
}
.n-tip__bubble {
    position: absolute;
    z-index: 1200;
    pointer-events: none;
    white-space: nowrap;
    padding: 6px 9px;
    border-radius: 8px;
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border-2);
    font-size: 12.5px;
    font-weight: 600;
    box-shadow: var(--shadow-md);
    opacity: 0;
    transform: scale(0.96);
    transition:
        opacity 0.14s,
        transform 0.14s;
}
.n-tip:hover .n-tip__bubble,
.n-tip:focus-visible .n-tip__bubble {
    opacity: 1;
    transform: scale(1);
}
.n-tip--top .n-tip__bubble {
    bottom: calc(100% + 7px);
    left: 50%;
    translate: -50% 0;
}
.n-tip--bottom .n-tip__bubble {
    top: calc(100% + 7px);
    left: 50%;
    translate: -50% 0;
}
.n-tip--left .n-tip__bubble {
    right: calc(100% + 7px);
    top: 50%;
    translate: 0 -50%;
}
.n-tip--right .n-tip__bubble {
    left: calc(100% + 7px);
    top: 50%;
    translate: 0 -50%;
}
/* Keep the fade, drop the scale, for users who ask for reduced motion. */
@media (prefers-reduced-motion: reduce) {
    .n-tip__bubble {
        transform: none;
        transition: opacity 0.14s;
    }
}
</style>
