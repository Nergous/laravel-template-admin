<script setup>
// NCard — surface container with border and padding. Default slot is the content.
// hover adds a lift/elevation on mouseover; padding overrides the default spacing.
// `hover` is a visual affordance only — clickable cards must add their own
// focus/cursor/role at the call site.
defineProps({
    // Spec cards use 20px or 24px. Default 24; pass "20px" (or e.g. "18px") to override.
    padding: { type: String, default: "" },
    hover: { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="n-card"
        :class="{ 'n-card--hover': hover }"
        :style="padding ? { padding } : null"
    >
        <slot />
    </div>
</template>

<style scoped>
.n-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: var(--sp-6);
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease,
        color 0.15s ease,
        box-shadow 0.15s ease,
        transform 0.15s ease;
}
.n-card--hover:hover {
    border-color: var(--accent);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
/* Suppress the vestibular lift for users who ask for reduced motion. */
@media (prefers-reduced-motion: reduce) {
    .n-card--hover:hover {
        transform: none;
    }
}
</style>
