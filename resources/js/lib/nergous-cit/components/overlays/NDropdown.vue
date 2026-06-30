<script setup>
import { ref, watch, nextTick, onBeforeUnmount, useId } from "vue";
import NIcon from "../primitives/NIcon.vue";

// NDropdown — WAI-ARIA menu button. Trigger goes in the default slot (receives
// { open }). Emits `select` with the chosen item; that item's action?.() also runs.
// items: [{ label, icon?, danger?, divider?, selected?, action? }]. align: left | right.
// Keyboard: ↓/Enter/Space open & focus first item; ↑/↓/Home/End move; Esc/Tab close.
const props = defineProps({
    items: { type: Array, default: () => [] },
    align: {
        type: String,
        default: "left",
        validator: (v) => ["left", "right"].includes(v),
    }, // left | right
});
const emit = defineEmits(["select"]);

const open = ref(false);
const root = ref(null);
const menuEl = ref(null);
const menuId = useId();
let triggerReturnEl = null; // element focus returns to on close

function menuItems() {
    return menuEl.value
        ? Array.from(menuEl.value.querySelectorAll(".n-dd__item"))
        : [];
}
function focusItem(i) {
    const items = menuItems();
    if (items.length) items[(i + items.length) % items.length].focus();
}

function openMenu(focusFirst) {
    triggerReturnEl =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
    open.value = true;
    if (focusFirst) nextTick(() => focusItem(0));
}
function closeMenu(returnFocus) {
    open.value = false;
    if (returnFocus && triggerReturnEl) triggerReturnEl.focus();
}
function toggle() {
    open.value ? closeMenu(false) : openMenu(false);
}

function onTriggerKeydown(e) {
    if (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        openMenu(true);
    }
}
function onMenuKeydown(e) {
    const items = menuItems();
    if (!items.length) return;
    const cur = items.indexOf(document.activeElement);
    switch (e.key) {
        case "ArrowDown":
            e.preventDefault();
            focusItem(cur + 1);
            break;
        case "ArrowUp":
            e.preventDefault();
            focusItem(cur - 1);
            break;
        case "Home":
            e.preventDefault();
            focusItem(0);
            break;
        case "End":
            e.preventDefault();
            focusItem(items.length - 1);
            break;
        case "Escape":
            e.preventDefault();
            closeMenu(true);
            break;
        case "Tab":
            closeMenu(false);
            break;
    }
}

function pick(it) {
    if (it.divider) return;
    closeMenu(true);
    it.action?.();
    emit("select", it);
}

function onDocClick(e) {
    if (root.value && !root.value.contains(e.target)) closeMenu(false);
}
// Only watch for outside clicks while the menu is open.
watch(open, (isOpen) => {
    if (isOpen) document.addEventListener("click", onDocClick);
    else document.removeEventListener("click", onDocClick);
});
onBeforeUnmount(() => document.removeEventListener("click", onDocClick));
</script>

<template>
    <div ref="root" class="n-dd">
        <span
            class="n-dd__trigger"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-controls="open ? menuId : undefined"
            @click="toggle"
            @keydown="onTriggerKeydown"
            ><slot :open="open"
        /></span>
        <Transition name="n-dd-pop">
            <div
                v-if="open"
                :id="menuId"
                ref="menuEl"
                class="n-dd__menu"
                :class="'align-' + align"
                role="menu"
                @keydown="onMenuKeydown"
            >
                <template v-for="(it, i) in items" :key="i">
                    <div
                        v-if="it.divider"
                        class="n-dd__divider"
                        role="separator"
                    />
                    <button
                        v-else
                        type="button"
                        class="n-dd__item"
                        :class="{ danger: it.danger }"
                        :role="
                            it.selected !== undefined
                                ? 'menuitemcheckbox'
                                : 'menuitem'
                        "
                        :aria-checked="
                            it.selected !== undefined
                                ? !!it.selected
                                : undefined
                        "
                        tabindex="-1"
                        @click="pick(it)"
                    >
                        <span
                            v-if="it.selected !== undefined"
                            class="n-dd__check"
                            :class="{ on: it.selected }"
                        >
                            <NIcon name="check" :size="11" />
                        </span>
                        <NIcon
                            v-if="it.icon"
                            :name="it.icon"
                            :size="16"
                            class="n-dd__icon"
                        />
                        {{ it.label }}
                    </button>
                </template>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.n-dd {
    position: relative;
    display: inline-flex;
}
.n-dd__trigger {
    display: inline-flex;
    cursor: pointer;
}
.n-dd__menu {
    position: absolute;
    top: calc(100% + 6px);
    min-width: 184px;
    z-index: 1200;
    padding: 6px;
    background: var(--surface);
    border: 1px solid var(--border-2);
    border-radius: 12px;
    box-shadow: var(--shadow-lg);
}
.n-dd__menu.align-left {
    left: 0;
}
.n-dd__menu.align-right {
    right: 0;
}
.n-dd__item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 9px 11px;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: var(--text);
    font-family: inherit;
    font-weight: 600;
    font-size: 13.5px;
    white-space: nowrap;
    text-align: left;
    cursor: pointer;
    transition: 0.1s;
}
.n-dd__item:hover {
    background: var(--surface-3);
}
.n-dd__item:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: -2px;
    background: var(--surface-3);
}
.n-dd__item.danger {
    color: var(--danger);
}
.n-dd__item.danger:hover,
.n-dd__item.danger:focus-visible {
    background: var(--danger-bg);
}
.n-dd__icon {
    color: var(--text-3);
}
.n-dd__item.danger .n-dd__icon {
    color: var(--danger);
}
.n-dd__check {
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
    opacity: 0;
    transition: opacity 0.15s;
}
.n-dd__check.on {
    opacity: 1;
}
.n-dd__divider {
    height: 1px;
    background: var(--border);
    margin: 5px 0;
}
.n-dd-pop-enter-active,
.n-dd-pop-leave-active {
    transition:
        opacity 0.14s ease,
        transform 0.14s ease;
}
.n-dd-pop-enter-from,
.n-dd-pop-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
/* Fade only — no slide — for users who ask for reduced motion. */
@media (prefers-reduced-motion: reduce) {
    .n-dd-pop-enter-from,
    .n-dd-pop-leave-to {
        transform: none;
    }
}
</style>
