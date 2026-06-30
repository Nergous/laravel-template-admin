<script setup>
import { ref, watch } from "vue";
import NAnchorNav from "./NAnchorNav.vue";
import { useScrollSpy } from "../../composables/useScrollSpy.js";

// NAnchoredForm — single-page editor shell for large records: a sticky
// table-of-contents rail (NAnchorNav) beside one scrollable column of sections.
// Owns the scroll container and the scroll-spy, so clicking a section smooth-
// scrolls to it and scrolling updates the active rail item — both surfaced
// through v-model (the active section value). The companion to NWizard: wizard
// for create, anchored form for edit.
//
// sections: [{ value, label, count }]. Each section's heading is rendered from
// `label`; its fields come from a per-section slot named `section-<value>`.
//
// Slots:
//   #section-<value> — fields for that section (scoped: { section, index }).
//   #header          — top of the rail (e.g. record identity card).
//   #status          — pinned to the rail bottom (e.g. "Draft · unsaved").
//   #savebar         — content of the floating save bar (text + buttons); the
//                      bar chrome only renders when this slot is provided.
const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    sections: { type: Array, default: () => [] },
    // Caption above the rail nav (e.g. "Sections"). Empty hides it.
    sectionsLabel: { type: String, default: "" },
    // Height of the rail+content card (the content column scrolls inside it).
    height: { type: String, default: "560px" },
    // Distance from the top of the viewport at which a section becomes active.
    offset: { type: Number, default: 16 },
    // Accessible name for the rail <nav> (forwarded to NAnchorNav).
    navLabel: { type: String, default: "Sections" },
});
const emit = defineEmits(["update:modelValue"]);

const scrollEl = ref(null);
const { active, scrollTo } = useScrollSpy(scrollEl, {
    offset: () => props.offset,
});

// Spy reports the raw data-spy string; map it back to the original section
// value (which may be a number) before bubbling it through v-model.
watch(active, (str) => {
    if (str == null) return;
    const match = props.sections.find((s) => String(s.value) === str);
    if (match && match.value !== props.modelValue)
        emit("update:modelValue", match.value);
});

function onPick(value) {
    emit("update:modelValue", value);
    scrollTo(value);
}
</script>

<template>
    <div class="n-aform">
        <div class="n-aform__card" :style="{ height }">
            <div class="n-aform__rail">
                <div v-if="$slots.header" class="n-aform__railHead">
                    <slot name="header" />
                </div>
                <div v-if="sectionsLabel" class="n-aform__eyebrow">
                    {{ sectionsLabel }}
                </div>
                <NAnchorNav
                    :model-value="modelValue"
                    :sections="sections"
                    :nav-label="navLabel"
                    @update:model-value="onPick"
                />
                <span class="n-aform__spacer" />
                <div v-if="$slots.status" class="n-aform__status">
                    <slot name="status" />
                </div>
            </div>

            <div ref="scrollEl" class="n-aform__scroll">
                <section
                    v-for="(s, i) in sections"
                    :key="s.value"
                    :data-spy="String(s.value)"
                    class="n-aform__section"
                >
                    <h3 class="n-aform__sectionTitle">{{ s.label }}</h3>
                    <slot
                        :name="'section-' + s.value"
                        :section="s"
                        :index="i"
                    />
                </section>
            </div>
        </div>

        <div v-if="$slots.savebar" class="n-aform__savebarWrap">
            <div class="n-aform__savebar">
                <slot name="savebar" />
            </div>
        </div>
    </div>
</template>

<style scoped>
.n-aform__card {
    display: grid;
    grid-template-columns: 230px 1fr;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.n-aform__rail {
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--border);
    background: var(--surface-2);
    padding: 18px 12px;
}
.n-aform__railHead {
    padding: 0 8px 14px;
}
.n-aform__eyebrow {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--text-3);
    margin: 0 8px 8px;
}
.n-aform__spacer {
    flex: 1;
}
.n-aform__status {
    margin-top: 10px;
    padding: 10px 10px 0;
    border-top: 1px solid var(--border);
    font-size: 12px;
    color: var(--text-3);
}
.n-aform__scroll {
    position: relative;
    overflow-y: auto;
    padding: 0 26px;
}
.n-aform__section {
    padding: 22px 0;
    border-bottom: 1px solid var(--border);
}
.n-aform__section:last-child {
    border-bottom: none;
}
.n-aform__sectionTitle {
    margin: 0 0 16px;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.01em;
    color: var(--text);
}
.n-aform__savebarWrap {
    position: sticky;
    bottom: 12px;
    display: flex;
    justify-content: center;
    padding-top: 12px;
}
.n-aform__savebar {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 12px 10px 20px;
    background: var(--surface);
    border: 1px solid var(--border-2);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
}
@media (max-width: 640px) {
    .n-aform__card {
        grid-template-columns: 1fr;
    }
    .n-aform__rail {
        border-right: none;
        border-bottom: 1px solid var(--border);
    }
}
</style>
