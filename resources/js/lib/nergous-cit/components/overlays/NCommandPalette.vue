<script setup>
import {
    ref,
    computed,
    watch,
    onMounted,
    onBeforeUnmount,
    nextTick,
    useId,
} from "vue";
import NIcon from "../primitives/NIcon.vue";
import { useFocusTrap } from "../../composables/useFocusTrap.js";
import { useScrollLock } from "../../composables/useScrollLock.js";
import { useDismiss } from "../../composables/useDismiss.js";
import { useInert } from "../../composables/useInert.js";

// NCommandPalette — ⌘K / Ctrl+K command launcher (modal combobox). v-model controls
// open state. Emits `run` with the chosen command (its action?.() also runs) and
// `update:query` on every input change (drive server-side search).
// Props: placeholder, emptyText, navHint, selectHint (English defaults; app localizes),
//        shortcut (bind Cmd/Ctrl+K globally), filter (false = parent owns filtering).
// commands: [{ label, icon?, hint?, action? }]
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    commands: { type: Array, default: () => [] },
    placeholder: { type: String, default: "Search…" },
    emptyText: { type: String, default: "No results" },
    navHint: { type: String, default: "Navigate" },
    selectHint: { type: String, default: "Select" },
    shortcut: { type: Boolean, default: true }, // bind Cmd/Ctrl+K
    filter: { type: Boolean, default: true }, // false: parent owns filtering (server search)
});
const emit = defineEmits(["update:modelValue", "run", "update:query"]);

const query = ref("");
const index = ref(0);
const inputEl = ref(null);
const dialogEl = ref(null);
const baseId = useId();
const listId = `${baseId}-list`;
const optId = (i) => `${baseId}-opt-${i}`;

const results = computed(() => {
    if (!props.filter) return props.commands;
    const q = query.value.trim().toLowerCase();
    return q
        ? props.commands.filter((c) => c.label.toLowerCase().includes(q))
        : props.commands;
});
// The combobox's "virtual focus" — id of the active option for aria-activedescendant.
const activeOptionId = computed(() =>
    results.value.length
        ? optId(Math.min(index.value, results.value.length - 1))
        : undefined,
);

// Before useFocusTrap so inert is released before focus returns to the trigger.
useInert(() => props.modelValue);
useFocusTrap(dialogEl, () => props.modelValue);
useScrollLock(() => props.modelValue);
// Escape closes only the topmost overlay (shared dismiss stack), not the whole stack.
useDismiss(() => props.modelValue, close);

function close() {
    emit("update:modelValue", false);
}
function run(c) {
    if (!c) return;
    close();
    c.action?.();
    emit("run", c);
}

watch(
    () => props.modelValue,
    (v) => {
        if (v) {
            query.value = "";
            index.value = 0;
            nextTick(() => inputEl.value?.focus());
        }
    },
);
watch(query, (q) => {
    index.value = 0;
    emit("update:query", q);
});

function onKey(e) {
    const k = (e.key || "").toLowerCase();
    if (props.shortcut && (e.metaKey || e.ctrlKey) && k === "k") {
        e.preventDefault();
        emit("update:modelValue", !props.modelValue);
        return;
    }
    if (!props.modelValue) return;
    // Escape is handled by useDismiss (topmost-overlay-only); arrows/Enter below.
    if (e.key === "ArrowDown") {
        e.preventDefault();
        index.value = Math.min(index.value + 1, results.value.length - 1);
    } else if (e.key === "ArrowUp") {
        e.preventDefault();
        index.value = Math.max(index.value - 1, 0);
    } else if (e.key === "Enter") {
        e.preventDefault();
        run(results.value[index.value]);
    }
}
onMounted(() => window.addEventListener("keydown", onKey));
onBeforeUnmount(() => window.removeEventListener("keydown", onKey));
</script>

<template>
    <Teleport to="body">
        <Transition name="n-cmd-fade">
            <div
                v-if="modelValue"
                class="n-cmd__overlay"
                data-overlay
                @click.self="close"
            >
                <div
                    ref="dialogEl"
                    class="n-cmd"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="placeholder"
                >
                    <div class="n-cmd__head">
                        <NIcon name="search" :size="18" class="n-cmd__search" />
                        <input
                            ref="inputEl"
                            v-model="query"
                            type="text"
                            class="n-cmd__input"
                            role="combobox"
                            aria-expanded="true"
                            aria-autocomplete="list"
                            :aria-controls="listId"
                            :aria-activedescendant="activeOptionId"
                            :aria-label="placeholder"
                            :placeholder="placeholder"
                        />
                        <kbd class="n-cmd__kbd">ESC</kbd>
                    </div>
                    <div :id="listId" class="n-cmd__list" role="listbox">
                        <div
                            v-for="(c, i) in results"
                            :id="optId(i)"
                            :key="i"
                            class="n-cmd__item"
                            :class="{ on: i === index }"
                            role="option"
                            :aria-selected="i === index"
                            @mousemove="index = i"
                            @click="run(c)"
                        >
                            <span class="n-cmd__icon"
                                ><NIcon
                                    :name="c.icon || 'chevron-right'"
                                    :size="16"
                            /></span>
                            <span class="n-cmd__label">{{ c.label }}</span>
                            <span v-if="c.hint" class="n-cmd__hint">{{
                                c.hint
                            }}</span>
                        </div>
                        <div v-if="!results.length" class="n-cmd__empty">
                            {{ emptyText }}
                        </div>
                    </div>
                    <div class="n-cmd__foot">
                        <span><kbd>↑↓</kbd> {{ navHint }}</span>
                        <span><kbd>↵</kbd> {{ selectHint }}</span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.n-cmd__overlay {
    position: fixed;
    inset: 0;
    background: var(--overlay);
    backdrop-filter: blur(3px);
    z-index: 1200;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 12vh;
}
.n-cmd {
    width: min(580px, 92vw);
    background: var(--surface);
    border: 1px solid var(--border-2);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}
.n-cmd__head {
    display: flex;
    align-items: center;
    gap: 11px;
    margin: 12px;
    padding: 0 12px;
    height: var(--control-h);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    transition:
        border-color 0.16s ease,
        box-shadow 0.16s ease;
}
.n-cmd__head:focus-within {
    border-color: var(--accent);
}
.n-cmd__search {
    color: var(--text-3);
}
.n-cmd__input {
    flex: 1;
    border: none;
    outline: none;
    background: transparent;
    color: var(--text);
    font-family: inherit;
    font-size: var(--fs);
    font-weight: 500;
}
.n-cmd__input::placeholder {
    color: var(--text-3);
}
.n-cmd__kbd,
.n-cmd__foot kbd {
    font-family: inherit;
    font-size: 11px;
    font-weight: 700;
    background: var(--surface-3);
    border: 1px solid var(--border);
    border-radius: 5px;
    padding: 2px 6px;
    color: var(--text-3);
}
.n-cmd__list {
    max-height: 46vh;
    overflow-y: auto;
    padding: 8px;
}
.n-cmd__item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 10px 12px;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text);
    text-align: left;
    cursor: pointer;
}
.n-cmd__item:hover {
    background: var(--surface-3);
}
.n-cmd__item.on {
    background: var(--accent-soft);
    color: var(--accent-ink);
}
.n-cmd__icon {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--surface-3);
    color: var(--text-2);
}
.n-cmd__item.on .n-cmd__icon {
    background: var(--surface);
    color: var(--accent);
}
.n-cmd__label {
    flex: 1;
    font-weight: 600;
    font-size: 14px;
}
.n-cmd__hint {
    font-size: 11.5px;
    color: var(--text-3);
    font-weight: 600;
}
.n-cmd__empty {
    padding: 32px;
    text-align: center;
    color: var(--text-3);
    font-size: 13.5px;
}
.n-cmd__foot {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 9px 16px;
    border-top: 1px solid var(--border);
    background: var(--surface-2);
    font-size: 11.5px;
    color: var(--text-3);
    font-weight: 600;
}
.n-cmd__foot span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.n-cmd-fade-enter-active,
.n-cmd-fade-leave-active {
    transition: opacity 0.14s;
}
.n-cmd-fade-enter-from,
.n-cmd-fade-leave-to {
    opacity: 0;
}
</style>
