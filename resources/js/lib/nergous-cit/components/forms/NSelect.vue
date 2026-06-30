<script setup>
import {
    ref,
    computed,
    nextTick,
    onMounted,
    onBeforeUnmount,
    useId,
} from "vue";
import NIcon from "../primitives/NIcon.vue";
import { useFormField } from "../../composables/useFormField.js";

// NSelect — custom listbox styled to the design system (replaces the native
// <select> popup, which can't be themed cross-browser).
// v-model holds the chosen value; pass choices via :options.
// options: [{ value, label, disabled? }]. `placeholder` shows when the value
// matches no option. Keyboard: ↑/↓ move, Enter/Space select, Esc close, Home/End.
const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: "Select…" },
    error: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue"]);

// Surrounding NFormField (if any) supplies invalid/required/described-by/label.
const field = useFormField();
const invalid = computed(() => props.error || !!field?.invalid.value);

// useId() gives each instance stable, unique ids for ARIA wiring (matches the
// rest of the DS; SSR-safe vs. a module counter).
const sid = useId();
const listId = `${sid}-list`;
const optId = (i) => `${sid}-opt-${i}`;

const open = ref(false);
const root = ref(null);
const optionEls = ref([]);
const activeIndex = ref(-1);

const selected = computed(
    () => props.options.find((o) => o.value === props.modelValue) || null,
);
const displayLabel = computed(() =>
    selected.value ? selected.value.label : props.placeholder,
);

const setOptionRef = (i) => (el) => {
    if (el) optionEls.value[i] = el;
};

function indexOfValue() {
    return props.options.findIndex((o) => o.value === props.modelValue);
}

// Next non-disabled option index, wrapping around. dir: +1 forward, -1 back.
function nextEnabled(from, dir) {
    const n = props.options.length;
    if (!n) return -1;
    let i = from;
    for (let step = 0; step < n; step++) {
        i = (i + dir + n) % n;
        if (!props.options[i]?.disabled) return i;
    }
    return from;
}

function scrollActiveIntoView() {
    optionEls.value[activeIndex.value]?.scrollIntoView({ block: "nearest" });
}

function openMenu() {
    if (props.disabled || open.value) return;
    open.value = true;
    const cur = indexOfValue();
    activeIndex.value = cur >= 0 ? cur : nextEnabled(-1, 1);
    nextTick(scrollActiveIntoView);
}

function closeMenu() {
    open.value = false;
    activeIndex.value = -1;
}

function toggle() {
    open.value ? closeMenu() : openMenu();
}

function move(dir) {
    if (!open.value) return openMenu();
    const next = nextEnabled(activeIndex.value, dir);
    if (next >= 0) {
        activeIndex.value = next;
        nextTick(scrollActiveIntoView);
    }
}

function selectOption(opt) {
    if (!opt || opt.disabled) return;
    if (opt.value !== props.modelValue) emit("update:modelValue", opt.value);
    closeMenu();
}

function onOptionHover(i, opt) {
    if (!opt.disabled) activeIndex.value = i;
}

function onKeydown(e) {
    switch (e.key) {
        case "ArrowDown":
            e.preventDefault();
            move(1);
            break;
        case "ArrowUp":
            e.preventDefault();
            move(-1);
            break;
        case "Home":
            if (open.value) {
                e.preventDefault();
                activeIndex.value = nextEnabled(-1, 1);
                nextTick(scrollActiveIntoView);
            }
            break;
        case "End":
            if (open.value) {
                e.preventDefault();
                activeIndex.value = nextEnabled(0, -1);
                nextTick(scrollActiveIntoView);
            }
            break;
        case "Enter":
        case " ":
            e.preventDefault();
            open.value
                ? selectOption(props.options[activeIndex.value])
                : openMenu();
            break;
        case "Escape":
            if (open.value) {
                e.preventDefault();
                closeMenu();
            }
            break;
        case "Tab":
            if (open.value) closeMenu();
            break;
    }
}

function onDocClick(e) {
    if (root.value && !root.value.contains(e.target)) closeMenu();
}
onMounted(() => document.addEventListener("click", onDocClick));
onBeforeUnmount(() => document.removeEventListener("click", onDocClick));
</script>

<template>
    <div
        ref="root"
        class="n-select"
        :class="{ open, disabled, error: invalid }"
    >
        <button
            type="button"
            class="n-select__control"
            :class="{ placeholder: !selected }"
            role="combobox"
            aria-haspopup="listbox"
            :aria-expanded="open"
            :aria-invalid="invalid || undefined"
            :aria-required="field?.required.value || undefined"
            :aria-labelledby="field?.labelledby.value || undefined"
            :aria-describedby="field?.describedBy.value || undefined"
            :aria-controls="listId"
            :aria-activedescendant="
                open && activeIndex >= 0 ? optId(activeIndex) : undefined
            "
            :disabled="disabled"
            @click="toggle"
            @keydown="onKeydown"
        >
            <span class="n-select__value">{{ displayLabel }}</span>
            <NIcon name="chevron-down" :size="16" class="n-select__chev" />
        </button>

        <Transition name="n-select-pop">
            <ul v-if="open" :id="listId" class="n-select__list" role="listbox">
                <li
                    v-for="(opt, i) in options"
                    :key="opt.value"
                    :id="optId(i)"
                    :ref="setOptionRef(i)"
                    class="n-select__opt"
                    :class="{
                        active: i === activeIndex,
                        selected: opt.value === modelValue,
                        disabled: opt.disabled,
                    }"
                    role="option"
                    :aria-selected="opt.value === modelValue"
                    :aria-disabled="opt.disabled || undefined"
                    @click="selectOption(opt)"
                    @mousemove="onOptionHover(i, opt)"
                >
                    <span class="n-select__opt-label">{{ opt.label }}</span>
                    <span
                        v-if="opt.value === modelValue"
                        class="n-select__opt-check"
                    >
                        <NIcon name="check" :size="11" />
                    </span>
                </li>
            </ul>
        </Transition>
    </div>
</template>

<style scoped>
.n-select {
    position: relative;
    display: inline-flex;
}
.n-select__control {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    height: var(--control-h);
    padding: 0 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text-2);
    font-family: inherit;
    font-weight: 600;
    font-size: var(--fs);
    cursor: pointer;
    outline: none;
    transition:
        border-color 0.14s,
        box-shadow 0.14s;
}
.n-select__control:hover:not(:disabled) {
    border-color: var(--border-2);
}
.n-select.open .n-select__control,
.n-select__control:focus-visible {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent);
}
.n-select.error .n-select__control {
    border-color: var(--danger);
    border-width: 1.5px;
}
.n-select__control:disabled {
    background: var(--surface-3);
    color: var(--text-3);
    cursor: not-allowed;
}
.n-select__control.placeholder {
    color: var(--text-3);
    font-weight: 500;
}
.n-select__value {
    flex: 1;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.n-select__chev {
    flex: none;
    color: var(--text-3);
    transition: transform 0.16s;
}
.n-select.open .n-select__chev {
    transform: rotate(180deg);
}
.n-select__list {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    z-index: 1200;
    margin: 0;
    padding: 6px;
    min-width: 100%;
    max-height: 280px;
    overflow-y: auto;
    list-style: none;
    background: var(--surface);
    border: 1px solid var(--border-2);
    border-radius: 12px;
    box-shadow: var(--shadow-lg);
}
.n-select__opt {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 11px;
    border-radius: 8px;
    color: var(--text);
    font-weight: 600;
    font-size: 13.5px;
    cursor: pointer;
    user-select: none;
}
.n-select__opt-label {
    flex: 1;
    white-space: nowrap;
}
.n-select__opt-check {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 15px;
    height: 15px;
    border-radius: 5px;
    border: 1.6px solid var(--accent);
    background: var(--accent);
    color: #fff;
}
.n-select__opt.active {
    background: var(--accent-soft);
    color: var(--accent-ink);
}
.n-select__opt.disabled {
    color: var(--text-3);
    cursor: not-allowed;
}
.n-select__opt.disabled.active {
    background: transparent;
}
.n-select-pop-enter-active,
.n-select-pop-leave-active {
    transition:
        opacity 0.14s ease,
        transform 0.14s ease;
}
.n-select-pop-enter-from,
.n-select-pop-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
</style>
