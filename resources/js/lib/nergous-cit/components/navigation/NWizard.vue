<script setup>
import { computed } from "vue";
import NStepper from "./NStepper.vue";

// NWizard — stepped-form shell for large create flows where a drawer/modal is
// too cramped. Frames a top progress bar + an NStepper rail + the active step's
// panel + a footer, and owns step navigation. v-model holds the active step
// value; steps: [{ value, label, sub }] (passed straight to NStepper).
//
// Slots:
//   default  — body of the active step. Scoped: { step, index, count }.
//              The host renders the panel for `step.value` (v-if / component map).
//   #footer  — footer controls. Scoped: { index, count, isFirst, isLast,
//              prev, next, goTo } — wire your own Back/Next/Save buttons and
//              step counter so the copy stays in the host (locale-agnostic).
//
// Presentational: the only owned string is `title` (the rail eyebrow), which the
// host passes in. Step gating is up to the host via v-model + the footer helpers.
const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    steps: { type: Array, default: () => [] },
    // Explicit done-set forwarded to NStepper; null = linear (index order).
    completed: { type: Array, default: null },
    // Eyebrow above the step rail (e.g. "New post"). Empty hides it.
    title: { type: String, default: "" },
    // Show the thin progress bar across the top.
    progress: { type: Boolean, default: true },
    // Accessible name for the step-rail <nav> (forwarded to NStepper).
    navLabel: { type: String, default: "Steps" },
});
const emit = defineEmits(["update:modelValue"]);

const count = computed(() => props.steps.length);
const index = computed(() => {
    const i = props.steps.findIndex((s) => s.value === props.modelValue);
    return i > -1 ? i : 0;
});
const activeStep = computed(() => props.steps[index.value] || null);
const isFirst = computed(() => index.value <= 0);
const isLast = computed(() => index.value >= count.value - 1);
const pct = computed(() =>
    count.value ? Math.round(((index.value + 1) / count.value) * 100) : 0,
);

function goTo(value) {
    emit("update:modelValue", value);
}
function next() {
    if (!isLast.value)
        emit("update:modelValue", props.steps[index.value + 1].value);
}
function prev() {
    if (!isFirst.value)
        emit("update:modelValue", props.steps[index.value - 1].value);
}
</script>

<template>
    <div class="n-wizard">
        <div
            v-if="progress"
            class="n-wizard__progress"
            role="progressbar"
            :aria-valuenow="pct"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <i class="n-wizard__progressBar" :style="{ width: pct + '%' }" />
        </div>

        <div class="n-wizard__body">
            <div class="n-wizard__rail">
                <div v-if="title" class="n-wizard__eyebrow">{{ title }}</div>
                <NStepper
                    :model-value="modelValue"
                    :steps="steps"
                    :completed="completed"
                    :nav-label="navLabel"
                    @update:model-value="goTo"
                />
            </div>

            <div class="n-wizard__content">
                <slot :step="activeStep" :index="index" :count="count" />
            </div>
        </div>

        <div v-if="$slots.footer" class="n-wizard__foot">
            <slot
                name="footer"
                :index="index"
                :count="count"
                :isFirst="isFirst"
                :isLast="isLast"
                :prev="prev"
                :next="next"
                :goTo="goTo"
            />
        </div>
    </div>
</template>

<style scoped>
.n-wizard {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.n-wizard__progress {
    height: 4px;
    background: var(--surface-3);
}
.n-wizard__progressBar {
    display: block;
    height: 100%;
    background: var(--accent);
    transition: width 0.35s ease;
}
.n-wizard__body {
    display: grid;
    grid-template-columns: 256px 1fr;
}
.n-wizard__rail {
    border-right: 1px solid var(--border);
    background: var(--surface-2);
    padding: 20px 14px;
}
.n-wizard__eyebrow {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--text-3);
    margin: 0 8px 12px;
}
.n-wizard__content {
    min-width: 0;
    padding: 24px 24px 4px;
    min-height: 344px;
}
.n-wizard__foot {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 24px;
    border-top: 1px solid var(--border);
}
@media (max-width: 640px) {
    .n-wizard__body {
        grid-template-columns: 1fr;
    }
    .n-wizard__rail {
        border-right: none;
        border-bottom: 1px solid var(--border);
    }
}
@media (prefers-reduced-motion: reduce) {
    .n-wizard__progressBar {
        transition: none;
    }
}
</style>
