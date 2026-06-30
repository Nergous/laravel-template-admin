<script setup>
import { computed } from "vue";
import NIcon from "../primitives/NIcon.vue";

// NStepper — vertical step rail for multi-step flows. v-model holds the active
// step value; steps: [{ value, label, sub }]. Each step renders as
// done / current / todo: by default every step before the active one (in array
// order) is "done"; pass `completed` (array of step values) to mark done steps
// explicitly when the flow isn't strictly linear. Steps are buttons — a click
// emits update:modelValue, so the host decides via v-model whether the jump is
// allowed. Presentational and locale-agnostic: all copy comes from `steps`.
// Used standalone or as the rail inside NWizard.
const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    steps: { type: Array, default: () => [] },
    // Explicit done-set; when null, steps before the active index are "done".
    completed: { type: Array, default: null },
    // Accessible name for the <nav> landmark (English default; app localizes).
    navLabel: { type: String, default: "Steps" },
});
const emit = defineEmits(["update:modelValue"]);

const activeIndex = computed(() =>
    props.steps.findIndex((s) => s.value === props.modelValue),
);

function stateOf(step, i) {
    if (step.value === props.modelValue) return "current";
    const done = props.completed
        ? props.completed.includes(step.value)
        : activeIndex.value > -1 && i < activeIndex.value;
    return done ? "done" : "todo";
}
</script>

<template>
    <nav class="n-stepper" :aria-label="navLabel">
        <button
            v-for="(s, i) in steps"
            :key="s.value"
            type="button"
            class="n-stepper__step"
            :class="'is-' + stateOf(s, i)"
            :aria-current="s.value === modelValue ? 'step' : undefined"
            @click="emit('update:modelValue', s.value)"
        >
            <span class="n-stepper__circle">
                <NIcon
                    v-if="stateOf(s, i) === 'done'"
                    name="check"
                    :size="13"
                />
                <template v-else>{{ i + 1 }}</template>
            </span>
            <span class="n-stepper__text">
                <span class="n-stepper__label">{{ s.label }}</span>
                <span v-if="s.sub" class="n-stepper__sub">{{ s.sub }}</span>
            </span>
        </button>
    </nav>
</template>

<style scoped>
.n-stepper {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.n-stepper__step {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    width: 100%;
    text-align: left;
    background: transparent;
    border: none;
    border-radius: var(--radius-md);
    padding: 9px;
    font-family: inherit;
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.n-stepper__step:hover:not(.is-current) {
    background: var(--surface-3);
}
.n-stepper__step.is-current {
    background: var(--accent-soft);
}
.n-stepper__step:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-stepper__circle {
    width: 26px;
    height: 26px;
    flex: none;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 12.5px;
    border: 1.6px solid var(--border-2);
    background: var(--surface);
    color: var(--text-3);
}
.is-current .n-stepper__circle {
    border-color: var(--accent);
    background: var(--accent-soft);
    color: var(--accent-ink);
}
.is-done .n-stepper__circle {
    border-color: var(--accent);
    background: var(--accent);
    color: var(--accent-on);
}
.n-stepper__text {
    min-width: 0;
    padding-top: 1px;
}
.n-stepper__label {
    display: block;
    font-size: 13.5px;
    font-weight: 700;
    line-height: 1.2;
    color: var(--text);
}
.is-todo .n-stepper__label {
    color: var(--text-3);
}
.n-stepper__sub {
    display: block;
    margin-top: 2px;
    font-size: 11.5px;
    color: var(--text-3);
}
</style>
