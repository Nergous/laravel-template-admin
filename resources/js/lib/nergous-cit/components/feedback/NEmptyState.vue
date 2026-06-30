<script setup>
import NIcon from "../primitives/NIcon.vue";

// NEmptyState — placeholder for empty / no-results views.
// Props: icon, title, description. Default slot is an optional action (e.g. a button).
// For filter/search-driven empties, pass role="status" (falls through to the root)
// so screen readers announce when results disappear.
defineProps({
    icon: { type: String, default: "search" },
    title: { type: String, default: "Nothing found" },
    description: { type: String, default: "" },
});
</script>

<template>
    <div class="n-empty">
        <div class="n-empty__icon"><NIcon :name="icon" :size="24" /></div>
        <div v-if="title" class="n-empty__title">{{ title }}</div>
        <div v-if="description" class="n-empty__desc">{{ description }}</div>
        <div v-if="$slots.default" class="n-empty__action"><slot /></div>
    </div>
</template>

<style scoped>
.n-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    text-align: center;
    padding: 24px;
}
.n-empty__icon {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-lg);
    background: var(--accent-soft);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
}
.n-empty__title {
    font-weight: 700;
    font-size: 15.5px;
    color: var(--text);
}
.n-empty__desc {
    font-size: 13px;
    color: var(--text-3);
    max-width: 320px;
    line-height: 1.5;
}
.n-empty__action {
    margin-top: 12px;
}
</style>
