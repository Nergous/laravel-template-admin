<script setup>
import { computed } from "vue";
import NIcon from "../primitives/NIcon.vue";

// NPagination — page navigation. v-model:page is the current page; pages = total count.
// Locale-agnostic: prev/next aria-labels come from props (English defaults). The
// app may also pass an `aria-label` for the <nav> landmark (falls through to root).
const props = defineProps({
    page: { type: Number, default: 1 },
    pages: { type: Number, default: 1 },
    prevLabel: { type: String, default: "Previous page" },
    nextLabel: { type: String, default: "Next page" },
});
const emit = defineEmits(["update:page"]);
function go(p) {
    if (p >= 1 && p <= props.pages && p !== props.page) emit("update:page", p);
}

// Windowed page list with ellipses: always show first/last, current ±1.
const items = computed(() => {
    const total = Math.max(1, props.pages);
    const cur = Math.min(Math.max(1, props.page), total);
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
    const set = new Set([1, total, cur, cur - 1, cur + 1]);
    const list = [...set]
        .filter((p) => p >= 1 && p <= total)
        .sort((a, b) => a - b);
    const out = [];
    let prev = 0;
    for (const p of list) {
        if (p - prev > 1) out.push("…");
        out.push(p);
        prev = p;
    }
    return out;
});
</script>

<template>
    <nav class="n-pg">
        <button
            type="button"
            class="n-pg__nav"
            :disabled="page <= 1"
            :aria-label="prevLabel"
            @click="go(page - 1)"
        >
            <NIcon name="chevron-left" :size="15" />
        </button>
        <template v-for="(it, i) in items" :key="i">
            <span v-if="it === '…'" class="n-pg__gap" aria-hidden="true"
                >…</span
            >
            <button
                v-else
                type="button"
                class="n-pg__pg"
                :class="{ on: it === page }"
                :aria-current="it === page ? 'page' : undefined"
                @click="go(it)"
            >
                {{ it }}
            </button>
        </template>
        <button
            type="button"
            class="n-pg__nav"
            :disabled="page >= pages"
            :aria-label="nextLabel"
            @click="go(page + 1)"
        >
            <NIcon name="chevron-right" :size="15" />
        </button>
    </nav>
</template>

<style scoped>
.n-pg {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.n-pg__nav,
.n-pg__pg {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: var(--control-h);
    height: var(--control-h);
    padding: 0 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text-2);
    font-family: inherit;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition:
        background-color 0.14s ease,
        border-color 0.14s ease,
        color 0.14s ease,
        box-shadow 0.14s ease;
}
.n-pg__nav {
    padding: 0;
}
.n-pg__nav:hover:not(:disabled),
.n-pg__pg:not(.on):hover {
    background: var(--surface-3);
    border-color: var(--text-3);
}
.n-pg__pg.on {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}
.n-pg__nav:focus,
.n-pg__pg:focus {
    outline: none;
}
.n-pg__nav:focus-visible,
.n-pg__pg:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-pg__nav:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.n-pg__gap {
    min-width: 24px;
    text-align: center;
    color: var(--text-3);
    font-size: 13px;
    font-weight: 700;
    user-select: none;
}
</style>
