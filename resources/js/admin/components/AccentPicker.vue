<script setup lang="ts">
import { ref, type PropType } from "vue";
import { NIcon } from "nergous-ui-vue";

// Accent color presets as a radio group of swatches. Each swatch carries
// data-accent, so accents.css paints it with its own preset in the current
// theme. Keyboard: Tab enters the group, arrows/Home/End move and choose.
type Accent = { value: string; label: string };

const props = defineProps({
    modelValue: { type: String, required: true },
    options: { type: Array as PropType<Accent[]>, required: true },
});
const emit = defineEmits<{ "update:modelValue": [value: string] }>();

const buttons = ref<HTMLButtonElement[]>([]);
const current = () =>
    Math.max(
        0,
        props.options.findIndex((o) => o.value === props.modelValue),
    );

function choose(index: number) {
    const option = props.options[index];
    if (!option) return;
    emit("update:modelValue", option.value);
    buttons.value[index]?.focus();
}

function onKeydown(event: KeyboardEvent) {
    const last = props.options.length - 1;
    const index = current();
    const next = {
        ArrowRight: index === last ? 0 : index + 1,
        ArrowDown: index === last ? 0 : index + 1,
        ArrowLeft: index === 0 ? last : index - 1,
        ArrowUp: index === 0 ? last : index - 1,
        Home: 0,
        End: last,
    }[event.key];
    if (next === undefined) return;
    event.preventDefault();
    choose(next);
}
</script>

<template>
    <div class="accents" role="radiogroup" @keydown="onKeydown">
        <button
            v-for="(option, i) in options"
            :key="option.value"
            ref="buttons"
            type="button"
            role="radio"
            class="accent"
            :class="{ 'accent--on': option.value === modelValue }"
            :data-accent="option.value"
            :aria-checked="option.value === modelValue"
            :tabindex="i === current() ? 0 : -1"
            @click="choose(i)"
        >
            <span class="accent__dot" aria-hidden="true">
                <NIcon
                    v-if="option.value === modelValue"
                    name="check"
                    :size="14"
                />
            </span>
            <span class="accent__label">{{ option.label }}</span>
        </button>
    </div>
</template>

<style scoped>
.accents {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 10px;
}
.accent {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 13.5px;
    font-weight: 600;
    text-align: left;
    cursor: pointer;
    transition:
        border-color 0.15s ease,
        background-color 0.15s ease;
}
.accent:hover {
    border-color: var(--accent);
}
.accent--on {
    border-color: var(--accent);
    background: var(--accent-soft);
    color: var(--accent-ink);
}
.accent:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.accent__dot {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--accent);
    color: var(--accent-on);
    box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.08);
}
</style>
