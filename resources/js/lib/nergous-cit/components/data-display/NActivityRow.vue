<script setup>
// NActivityRow — presentational activity-feed row. Locale-agnostic and
// domain-agnostic: it renders structure + tones from props only. The
// consuming page supplies the localized verb, the tone/icon mapping, the
// subject-type tag, and the meta strings. Keep this component free of any
// app-specific text or action enum so it stays reusable across SPAs.
import NIcon from "../primitives/NIcon.vue";
import NBadge from "./NBadge.vue";

defineProps({
    // ok | info | danger | warn | accent — drives the icon-chip background/color.
    tone: {
        type: String,
        default: "info",
        validator: (v) =>
            ["ok", "info", "danger", "warn", "accent"].includes(v),
    },
    icon: { type: String, default: "" }, // NIcon name
    actor: { type: String, default: "" },
    verb: { type: String, default: "" }, // muted span — page passes localized verb
    object: { type: String, default: "" }, // emphasized
    tag: { type: String, default: "" }, // subject-type chip text
    time: { type: String, default: "" }, // meta left
    meta: { type: String, default: "" }, // meta right, mono — optional
});
</script>

<template>
    <div class="n-act">
        <span class="n-act__ic" :class="'n-act__ic--' + tone">
            <NIcon v-if="icon" :name="icon" :size="16" />
        </span>
        <div class="n-act__body">
            <div class="n-act__txt">
                <b v-if="actor">{{ actor }}</b>
                <span v-if="verb" class="n-act__verb">{{ verb }}</span>
                <span v-if="object" class="n-act__obj">{{ object }}</span>
                <NBadge v-if="tag" size="sm">{{ tag }}</NBadge>
            </div>
            <div v-if="time || meta" class="n-act__meta">
                <span v-if="time">{{ time }}</span>
                <span v-if="meta" class="n-act__mono">{{ meta }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.n-act {
    display: flex;
    gap: 13px;
    padding: var(--row-pad, 14px) 16px;
    border-bottom: 1px solid var(--border);
    /* --row-pad is a density token; smooth the change when density switches */
    transition: padding 0.18s ease;
}
.n-act:last-child {
    border-bottom: none;
}
.n-act__ic {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
}
.n-act__ic--ok {
    background: var(--ok-bg);
    color: var(--ok);
}
.n-act__ic--info {
    background: var(--info-bg);
    color: var(--info);
}
.n-act__ic--danger {
    background: var(--danger-bg);
    color: var(--danger);
}
.n-act__ic--warn {
    background: var(--warn-bg);
    color: var(--warn);
}
.n-act__ic--accent {
    background: var(--accent-soft);
    color: var(--accent);
}
.n-act__body {
    flex: 1;
    min-width: 0;
}
.n-act__txt {
    font-size: var(--fs, 14px);
    line-height: 1.4;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    column-gap: 5px;
    row-gap: 2px;
}
.n-act__txt b {
    font-weight: 700;
}
.n-act__verb {
    color: var(--text-2);
}
.n-act__obj {
    font-weight: 600;
}
.n-act__meta {
    display: flex;
    gap: 12px;
    margin-top: 3px;
    font-size: 11.5px;
    color: var(--text-3);
}
.n-act__mono {
    font-family: var(--font-mono);
}
</style>
