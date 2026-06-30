<script setup>
// NBrand — product brand lockup: a gradient glyph tile + name/sub text.
// Used in the sidebar rail and on auth screens. Set `showSub=false` (or use
// the collapsed sidebar) to render only the glyph.
// Props:
//   glyph   — single letter/short string inside the tile (default "N")
//   name    — primary brand name
//   sub     — secondary line under the name
//   size    — "sm" | "md" | "lg" (controls glyph + type scale)
//   showSub — toggle the name/sub block (hide for icon-only rails)
defineProps({
    glyph: { type: String, default: "N" },
    name: { type: String, default: "nergous-cit" },
    sub: { type: String, default: "" },
    size: {
        type: String,
        default: "md",
        validator: (v) => ["sm", "md", "lg"].includes(v),
    },
    showSub: { type: Boolean, default: true },
});
</script>

<template>
    <div class="n-brand" :class="'n-brand--' + size">
        <div
            class="n-brand__glyph"
            :role="showSub ? undefined : 'img'"
            :aria-label="showSub ? undefined : name"
            :aria-hidden="showSub ? 'true' : undefined"
        >
            {{ glyph }}
        </div>
        <div v-if="showSub" class="n-brand__txt">
            <b class="n-brand__name">{{ name }}</b>
            <span v-if="sub" class="n-brand__sub">{{ sub }}</span>
        </div>
    </div>
</template>

<style scoped>
.n-brand {
    display: flex;
    align-items: center;
    gap: 11px;
}
.n-brand__glyph {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: linear-gradient(140deg, var(--accent), #4aa3ff);
    color: #fff;
    font-weight: 800;
}
.n-brand__txt {
    display: flex;
    flex-direction: column;
    line-height: 1.05;
    overflow: hidden;
}
.n-brand__name {
    font-weight: 800;
    letter-spacing: -0.02em;
    white-space: nowrap;
    color: var(--text);
}
.n-brand__sub {
    color: var(--text-3);
    font-weight: 600;
    white-space: nowrap;
}

/* sizes */
/* sm — login lockup (spec line 551): 30×30 glyph, name 15 */
.n-brand--sm .n-brand__glyph {
    width: 30px;
    height: 30px;
    border-radius: 9px;
    font-size: 15px;
}
.n-brand--sm .n-brand__name {
    font-size: 15px;
}
.n-brand--sm .n-brand__sub {
    font-size: 11px;
}

/* md — header lockup (spec lines 64–68): 32×32 glyph, name 16 */
.n-brand--md .n-brand__glyph {
    width: 32px;
    height: 32px;
    border-radius: 9px;
    font-size: 16px;
}
.n-brand--md .n-brand__name {
    font-size: 16px;
}
.n-brand--md .n-brand__sub {
    font-size: 11.5px;
}

.n-brand--lg .n-brand__glyph {
    width: 36px;
    height: 36px;
    border-radius: 11px;
    font-size: 17px;
}
.n-brand--lg .n-brand__name {
    font-size: 16px;
}
.n-brand--lg .n-brand__sub {
    font-size: 12px;
}
</style>
