<script setup>
// NAnchorNav — vertical table-of-contents for a long single-page form. v-model
// holds the active section value; sections: [{ value, label, count }] (`count`
// optional — shown as a muted badge). Clicking a section emits update:modelValue.
// Purely presentational: it neither scrolls nor scroll-spies — NAnchoredForm
// owns the scroll container and drives the active value through v-model. Use
// standalone only if you wire those yourself (e.g. via useScrollSpy).
defineProps({
    modelValue: { type: [String, Number], default: "" },
    sections: { type: Array, default: () => [] },
    // Accessible name for the <nav> landmark (English default; app localizes).
    navLabel: { type: String, default: "Sections" },
});
const emit = defineEmits(["update:modelValue"]);
</script>

<template>
    <nav class="n-anchornav" :aria-label="navLabel">
        <button
            v-for="s in sections"
            :key="s.value"
            type="button"
            class="n-anchornav__item"
            :class="{ on: s.value === modelValue }"
            :aria-current="s.value === modelValue ? 'true' : undefined"
            @click="emit('update:modelValue', s.value)"
        >
            <span class="n-anchornav__dot" />
            <span class="n-anchornav__label">{{ s.label }}</span>
            <span v-if="s.count != null" class="n-anchornav__count">{{
                s.count
            }}</span>
        </button>
    </nav>
</template>

<style scoped>
.n-anchornav {
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.n-anchornav__item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    text-align: left;
    background: transparent;
    border: none;
    border-radius: var(--radius-sm);
    padding: 8px 10px;
    font-family: inherit;
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.n-anchornav__item:hover:not(.on) {
    background: var(--surface-3);
}
.n-anchornav__item.on {
    background: var(--accent-soft);
}
.n-anchornav__item:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-anchornav__dot {
    width: 6px;
    height: 6px;
    flex: none;
    border-radius: 50%;
    background: var(--border-2);
}
.n-anchornav__item.on .n-anchornav__dot {
    background: var(--accent);
}
.n-anchornav__label {
    flex: 1;
    min-width: 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-2);
}
.n-anchornav__item.on .n-anchornav__label {
    font-weight: 700;
    color: var(--accent-ink);
}
.n-anchornav__count {
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--text-3);
}
</style>
