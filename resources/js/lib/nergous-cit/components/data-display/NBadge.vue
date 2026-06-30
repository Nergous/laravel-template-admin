<script setup>
// NBadge — small status/label chip. Default slot is the text.
// dot adds a leading colored dot; swatch renders a colored square (role badges);
// pill makes it fully rounded.
// tone: neutral | accent | ok | warn | danger | info
// size: md (default) | sm (compact "tag" — always muted grey, ignores tone)
const props = defineProps({
    tone: {
        type: String,
        default: "neutral",
        validator: (v) =>
            ["neutral", "accent", "ok", "warn", "danger", "info"].includes(v),
    },
    dot: { type: Boolean, default: false },
    /** Colored square swatch (CSS color string) shown before the label — used by role badges. */
    swatch: { type: String, default: "" },
    pill: { type: Boolean, default: false },
    /** `md` (default) | `sm` (compact tag: 20px height, smaller text). */
    size: {
        type: String,
        default: "md",
        validator: (v) => ["md", "sm"].includes(v),
    },
});

// sm renders a fixed muted grey tag, so a non-neutral tone is silently dropped.
if (import.meta.env.DEV && props.size === "sm" && props.tone !== "neutral") {
    console.warn(
        `[NBadge] tone="${props.tone}" is ignored with size="sm" (sm renders a muted grey tag).`,
    );
}
</script>

<template>
    <span
        class="n-badge"
        :class="[
            'n-badge--' + tone,
            'n-badge--' + size,
            { 'n-badge--pill': pill },
        ]"
    >
        <span
            v-if="swatch"
            class="n-badge__swatch"
            :style="{ background: swatch }"
        />
        <span v-else-if="dot" class="n-badge__dot" />
        <slot />
    </span>
</template>

<style scoped>
.n-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 24px;
    padding: 0 9px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
}
.n-badge--pill {
    border-radius: 999px;
    padding: 0 10px;
    gap: 7px;
}
.n-badge__dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.n-badge__swatch {
    width: 7px;
    height: 7px;
    border-radius: 2px;
    flex: none;
}
.n-badge--neutral {
    color: var(--text-2);
    background: var(--surface-3);
}
.n-badge--accent {
    color: var(--accent-ink);
    background: var(--accent-soft);
}
.n-badge--ok {
    color: var(--ok);
    background: var(--ok-bg);
}
.n-badge--warn {
    color: var(--warn);
    background: var(--warn-bg);
}
.n-badge--danger {
    color: var(--danger);
    background: var(--danger-bg);
}
.n-badge--info {
    color: var(--info);
    background: var(--info-bg);
}
/* sm "tag" — compact; defaults to muted text-2 on surface-3 (overrides tone, after tone rules).
   Uses --text-2 (not --text-3) so the tag clears AA: it carries meaningful status
   (role type, activity subject) at 11px. */
.n-badge--sm {
    height: 20px;
    padding: 0 8px;
    border-radius: 6px;
    font-size: 11px;
    color: var(--text-2);
    background: var(--surface-3);
}
</style>
