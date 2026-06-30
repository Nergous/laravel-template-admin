<script setup>
import { computed, ref, watch } from "vue";

// NAvatar — user avatar; shows the image (src) or initials derived from `name`.
// status adds a presence dot (online/busy/away); square toggles rounded-square vs circle.
// Decorative by default (like NIcon): pass `alt` to announce it as a labelled image,
// otherwise it is hidden from assistive tech (the page renders the name alongside).
const props = defineProps({
    name: { type: String, default: "" },
    src: { type: String, default: "" },
    size: { type: Number, default: 38 },
    status: {
        type: String,
        default: "",
        validator: (v) => !v || ["online", "busy", "away"].includes(v),
    }, // online | busy | away
    square: { type: Boolean, default: true },
    alt: { type: String, default: "" }, // accessible label; empty = decorative
});

// Fall back to initials/gradient if the image fails to load; reset when src changes.
const failed = ref(false);
watch(
    () => props.src,
    () => (failed.value = false),
);

// Deterministic gradient presets (by name hash) — all linear-gradient(140deg, A, B).
const GRADS = [
    "#6f76f5,#9b6ff5",
    "#3bbf9a,#3b8fbf",
    "#f0a23b,#e06f5b",
    "#e0608a,#9b5fd0",
    "#5b8def,#3bbf9a",
    "#e0a23b,#d0603b",
];
const initials = computed(() => {
    const p = props.name.trim().split(/\s+/);
    return ((p[0] || "")[0] || "") + ((p[1] || "")[0] || "");
});
const grad = computed(() => {
    let h = 0;
    for (const ch of props.name) h = (h * 31 + ch.charCodeAt(0)) | 0;
    return GRADS[Math.abs(h) % GRADS.length];
});
// Canonical square radii per size (lg 52→10, md 42→10, sm 36→9); fallback scales for custom sizes.
const SQ_RADIUS = { 52: 10, 42: 10, 36: 9 };
const radius = computed(() => {
    if (!props.square) return "50%";
    const r = SQ_RADIUS[props.size] ?? Math.round(props.size * 0.24);
    return r + "px";
});
// Canonical font sizes per size (lg 52→18, md 42→15, sm 36→13); fallback scales for custom sizes.
const FONT = { 52: 18, 42: 15, 36: 13 };
const fontSize = computed(
    () => (FONT[props.size] ?? Math.round(props.size * 0.36)) + "px",
);
</script>

<template>
    <span
        class="n-avatar"
        :style="{
            width: size + 'px',
            height: size + 'px',
            borderRadius: radius,
            fontSize,
            backgroundImage:
                src && !failed ? 'none' : `linear-gradient(140deg, ${grad})`,
        }"
        :role="alt ? 'img' : undefined"
        :aria-label="alt || undefined"
    >
        <img
            v-if="src && !failed"
            :src="src"
            :style="{ borderRadius: radius }"
            alt=""
            loading="lazy"
            @error="failed = true"
        />
        <span v-else aria-hidden="true">{{ initials }}</span>
        <span v-if="status" class="n-avatar__status" :class="'is-' + status" />
    </span>
</template>

<style scoped>
.n-avatar {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    flex: none;
}
.n-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.n-avatar__status {
    position: absolute;
    bottom: -1px;
    right: -1px;
    width: 30%;
    height: 30%;
    min-width: 10px;
    min-height: 10px;
    border-radius: 50%;
    border: 2.5px solid var(--surface);
}
.n-avatar__status.is-online {
    background: var(--ok);
}
.n-avatar__status.is-busy {
    background: var(--danger);
}
.n-avatar__status.is-away {
    background: var(--warn);
}
</style>
