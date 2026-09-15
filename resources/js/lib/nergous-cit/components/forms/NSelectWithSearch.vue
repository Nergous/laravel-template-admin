<script setup>
import {
    ref,
    computed,
    nextTick,
    onMounted,
    onBeforeUnmount,
    useId,
    watch,
} from "vue";
import NIcon from "../primitives/NIcon.vue";
import { useFormField } from "../../composables/useFormField.js";

// NSelectWithSearch — single-select listbox with a client-side search box.
// Same value contract as NSelect (v-model + :options [{value,label,disabled?}]);
// adds a filter field in the popup for long option lists.
// Keyboard: type to filter, ↑/↓ move, Enter select, Esc close, Home/End.
const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: "Select…" },
    searchPlaceholder: { type: String, default: "Search…" },
    noResultsText: { type: String, default: "No results" },
    error: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue"]);

const field = useFormField();
const invalid = computed(() => props.error || !!field?.invalid.value);

const sid = useId();
const listId = `${sid}-list`;
const optId = (i) => `${sid}-opt-${i}`;

const open = ref(false);
const root = ref(null);
const searchEl = ref(null);
const optionEls = ref([]);
const activeIndex = ref(-1);
const query = ref("");

const selected = computed(
    () => props.options.find((o) => o.value === props.modelValue) || null,
);
const displayLabel = computed(() =>
    selected.value ? selected.value.label : props.placeholder,
);

// Client-side filter by a case-insensitive substring of the label.
const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.options;
    return props.options.filter((o) =>
        String(o.label).toLowerCase().includes(q),
    );
});

const setOptionRef = (i) => (el) => {
    if (el) optionEls.value[i] = el;
};

// Index (within `filtered`) of the currently selected value, or -1.
function selectedFilteredIndex() {
    return filtered.value.findIndex((o) => o.value === props.modelValue);
}

function firstEnabled() {
    return filtered.value.findIndex((o) => !o.disabled);
}

// Next non-disabled index in `filtered`, wrapping. dir: +1 forward, -1 back.
function nextEnabled(from, dir) {
    const n = filtered.value.length;
    if (!n) return -1;
    let i = from;
    for (let step = 0; step < n; step++) {
        i = (i + dir + n) % n;
        if (!filtered.value[i]?.disabled) return i;
    }
    return from;
}

function scrollActiveIntoView() {
    optionEls.value[activeIndex.value]?.scrollIntoView({ block: "nearest" });
}

function openMenu() {
    if (props.disabled || open.value) return;
    query.value = "";
    open.value = true;
    const cur = selectedFilteredIndex();
    activeIndex.value = cur >= 0 ? cur : firstEnabled();
    nextTick(() => {
        searchEl.value?.focus();
        scrollActiveIntoView();
    });
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

// Filtering changes the list: drop stale option refs and re-home the highlight.
watch(filtered, () => {
    optionEls.value = [];
    if (!open.value) return;
    const cur = selectedFilteredIndex();
    activeIndex.value = cur >= 0 ? cur : firstEnabled();
});

// Keys handled on the search field (which holds focus while the popup is open).
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
                activeIndex.value = firstEnabled();
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
            e.preventDefault();
            if (open.value) selectOption(filtered.value[activeIndex.value]);
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

function onTriggerKeydown(e) {
    if (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        openMenu();
    } else if (e.key === "Escape" && open.value) {
        e.preventDefault();
        closeMenu();
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
        class="n-selects"
        :class="{ open, disabled, error: invalid }"
    >
        <button
            type="button"
            class="n-selects__control"
            :class="{ placeholder: !selected }"
            role="combobox"
            aria-haspopup="listbox"
            :aria-expanded="open"
            :aria-controls="listId"
            :disabled="disabled"
            @click="toggle"
            @keydown="onTriggerKeydown"
        >
            <span class="n-selects__value">{{ displayLabel }}</span>
            <NIcon name="chevron-down" :size="16" class="n-selects__chev" />
        </button>

        <Transition name="n-selects-pop">
            <div v-if="open" class="n-selects__pop">
                <div class="n-selects__search-wrap">
                    <NIcon
                        name="search"
                        :size="15"
                        class="n-selects__search-icon"
                    />
                    <input
                        ref="searchEl"
                        v-model="query"
                        type="text"
                        class="n-selects__search"
                        :placeholder="searchPlaceholder"
                        role="searchbox"
                        :aria-controls="listId"
                        autocomplete="off"
                        @keydown="onKeydown"
                    />
                </div>

                <ul :id="listId" class="n-selects__list" role="listbox">
                    <li
                        v-for="(opt, i) in filtered"
                        :key="opt.value"
                        :id="optId(i)"
                        :ref="setOptionRef(i)"
                        class="n-selects__opt"
                        :class="{
                            active: i === activeIndex,
                            selected: opt.value === modelValue,
                            disabled: opt.disabled,
                        }"
                        role="option"
                        :aria-selected="opt.value === modelValue"
                        :aria-disabled="opt.disabled || undefined"
                        @click.stop.prevent="selectOption(opt)"
                        @mousemove="onOptionHover(i, opt)"
                    >
                        <span class="n-selects__opt-label">{{
                            opt.label
                        }}</span>
                        <span
                            v-if="opt.value === modelValue"
                            class="n-selects__opt-check"
                        >
                            <NIcon name="check" :size="11" />
                        </span>
                    </li>
                    <li v-if="!filtered.length" class="n-selects__empty">
                        {{ noResultsText }}
                    </li>
                </ul>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.n-selects {
    position: relative;
    display: flex;
    width: 100%;
}
.n-selects__control {
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
.n-selects__control:hover:not(:disabled) {
    border-color: var(--border-2);
}
.n-selects.open .n-selects__control,
.n-selects__control:focus-visible {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent);
}
.n-selects.error .n-selects__control {
    border-color: var(--danger);
    border-width: 1.5px;
}
.n-selects__control:disabled {
    background: var(--surface-3);
    color: var(--text-3);
    cursor: not-allowed;
}
.n-selects__control.placeholder {
    color: var(--text-3);
    font-weight: 500;
}
.n-selects__value {
    flex: 1;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.n-selects__chev {
    flex: none;
    color: var(--text-3);
    transition: transform 0.16s;
}
.n-selects.open .n-selects__chev {
    transform: rotate(180deg);
}

/* popup: search box + scrollable list, capped to the control width */
.n-selects__pop {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    z-index: 1200;
    min-width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    padding: 6px;
    background: var(--surface);
    border: 1px solid var(--border-2);
    border-radius: 12px;
    box-shadow: var(--shadow-lg);
}
.n-selects__search-wrap {
    position: relative;
    display: flex;
    align-items: center;
    margin-bottom: 6px;
}
.n-selects__search-icon {
    position: absolute;
    left: 9px;
    color: var(--text-3);
    pointer-events: none;
}
.n-selects__search {
    width: 100%;
    box-sizing: border-box;
    height: 34px;
    padding: 0 10px 0 30px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-2);
    color: var(--text);
    font-family: inherit;
    font-size: 13.5px;
    outline: none;
}
.n-selects__search:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent);
}
.n-selects__list {
    margin: 0;
    padding: 0;
    max-height: 240px;
    overflow-y: auto;
    list-style: none;
}
.n-selects__opt {
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
.n-selects__opt.active {
    background: var(--surface-3);
}
.n-selects__opt.selected {
    color: var(--accent-ink);
}
.n-selects__opt.disabled {
    color: var(--text-3);
    cursor: not-allowed;
}
.n-selects__opt-label {
    flex: 1;
    min-width: 0;
    white-space: normal;
    overflow-wrap: anywhere;
}
.n-selects__opt-check {
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
.n-selects__empty {
    padding: 12px 11px;
    color: var(--text-3);
    font-size: 13px;
    text-align: center;
}

/* popup transition */
.n-selects-pop-enter-active,
.n-selects-pop-leave-active {
    transition:
        opacity 0.14s ease,
        transform 0.14s ease;
}
.n-selects-pop-enter-from,
.n-selects-pop-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>
