<script setup>
// NSpinner — indeterminate loading spinner. Props: size (px), width (stroke px).
// Color inherits currentColor, matching the design's button spinner.
// Decorative by default (aria-hidden); pass `label` to announce it as a live
// status region (omit when the surrounding container already does so).
defineProps({
    size: { type: Number, default: 24 },
    width: { type: Number, default: 2 },
    label: { type: String, default: "" },
});
</script>

<template>
    <span
        class="n-spinner"
        :role="label ? 'status' : undefined"
        :aria-label="label || undefined"
        :aria-hidden="label ? undefined : 'true'"
        :style="{
            width: size + 'px',
            height: size + 'px',
            borderWidth: width + 'px',
        }"
    />
</template>

<style scoped>
.n-spinner {
    display: inline-block;
    border-style: solid;
    border-color: currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: n-spin 0.7s linear infinite;
}
@keyframes n-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
