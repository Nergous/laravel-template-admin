<script setup>
// NIcon — line-icon set on a 24px grid, 1.8px stroke. Pass `name` + `size`.
// Decorative by default (aria-hidden); pass `title` to expose it as a labelled image.
// The icon registry lives in ./icons.js — this component is just the renderer.
import { computed } from "vue";
import { PATHS, CIRCLES } from "./icons.js";

const props = defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 18 },
    // Accessible label. When set, the icon is announced as an image; otherwise it is
    // hidden from assistive tech as decorative (the parent supplies the name).
    title: { type: String, default: "" },
});

const paths = computed(() => PATHS[props.name] || []);
const circle = computed(() => CIRCLES[props.name] || null);

// Surface typos in dev — an unknown name renders a silent empty <svg> otherwise.
if (import.meta.env.DEV && !PATHS[props.name]) {
    console.warn(`[NIcon] unknown icon name: "${props.name}"`);
}
</script>

<template>
    <svg
        class="n-icon"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        focusable="false"
        :role="title ? 'img' : undefined"
        :aria-label="title || undefined"
        :aria-hidden="title ? undefined : 'true'"
    >
        <title v-if="title">{{ title }}</title>
        <path v-for="(d, i) in paths" :key="i" :d="d" />
        <circle v-if="circle" v-bind="circle" />
    </svg>
</template>

<style scoped>
.n-icon {
    flex: none;
    display: inline-block;
    vertical-align: middle;
}
</style>
