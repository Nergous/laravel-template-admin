<script setup>
// NSkeleton — shimmering placeholder shown while content loads.
// Props: width, height, radius; circle makes it a round dot/avatar placeholder.
defineProps({
    width: { type: String, default: "100%" },
    height: { type: String, default: "12px" },
    radius: { type: String, default: "6px" },
    circle: { type: Boolean, default: false },
});
</script>

<template>
    <span
        class="n-skeleton"
        aria-hidden="true"
        :style="{
            width,
            height: circle ? 'auto' : height,
            borderRadius: circle ? '50%' : radius,
            aspectRatio: circle ? '1 / 1' : null,
        }"
    />
</template>

<style scoped>
.n-skeleton {
    display: block;
    background: linear-gradient(
        90deg,
        var(--surface-3) 25%,
        var(--surface-2) 37%,
        var(--surface-3) 63%
    );
    background-size: 400% 100%;
    animation: n-sk 1.4s ease infinite;
}
@keyframes n-sk {
    0% {
        background-position: 200% 0;
    }
    100% {
        background-position: -200% 0;
    }
}
/* Stop the looping shimmer for users who ask for reduced motion. */
@media (prefers-reduced-motion: reduce) {
    .n-skeleton {
        animation: none;
    }
}
</style>
