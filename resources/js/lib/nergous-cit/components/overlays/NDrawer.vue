<script setup>
import { ref, useId } from "vue";
import NIcon from "../primitives/NIcon.vue";
import { useFocusTrap } from "../../composables/useFocusTrap.js";
import { useScrollLock } from "../../composables/useScrollLock.js";
import { useDismiss } from "../../composables/useDismiss.js";
import { useInert } from "../../composables/useInert.js";

// NDrawer — slide-in side panel. v-model controls open state; emits `close` on dismiss.
// Props: title, subtitle, width, closeLabel. Default slot is the body; #footer slot
// receives { close }. Locks body scroll and traps focus while open.
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: "" },
    subtitle: { type: String, default: "" },
    width: { type: String, default: "440px" },
    closeLabel: { type: String, default: "Close" },
});
const emit = defineEmits(["update:modelValue", "close"]);
function close() {
    emit("update:modelValue", false);
    emit("close");
}

const dialogEl = ref(null);
const titleId = useId();
const subId = useId();
// Before useFocusTrap so inert is released before focus returns to the trigger.
useInert(() => props.modelValue);
useFocusTrap(dialogEl, () => props.modelValue);
useScrollLock(() => props.modelValue);
// Escape closes only the topmost overlay (shared dismiss stack), not the whole stack.
useDismiss(() => props.modelValue, close);
</script>

<template>
    <Teleport to="body">
        <Transition name="n-fade">
            <div
                v-if="modelValue"
                class="n-drawer__overlay"
                data-overlay
                @click.self="close"
            />
        </Transition>
        <Transition name="n-slide">
            <aside
                v-if="modelValue"
                ref="dialogEl"
                class="n-drawer"
                data-overlay
                role="dialog"
                aria-modal="true"
                :aria-labelledby="title ? titleId : undefined"
                :aria-describedby="subtitle ? subId : undefined"
                :style="{ width: 'min(' + width + ', 92%)' }"
            >
                <div class="n-drawer__head">
                    <div class="n-drawer__heading">
                        <h3 v-if="title" :id="titleId">{{ title }}</h3>
                        <span
                            v-if="subtitle"
                            :id="subId"
                            class="n-drawer__sub"
                            >{{ subtitle }}</span
                        >
                    </div>
                    <button
                        type="button"
                        class="n-drawer__x"
                        :aria-label="closeLabel"
                        @click="close"
                    >
                        <NIcon name="x" :size="18" />
                    </button>
                </div>
                <div class="n-drawer__body"><slot /></div>
                <div v-if="$slots.footer" class="n-drawer__foot">
                    <slot name="footer" :close="close" />
                </div>
            </aside>
        </Transition>
    </Teleport>
</template>

<style scoped>
.n-drawer__overlay {
    position: fixed;
    inset: 0;
    background: var(--overlay);
    z-index: 1000;
}
.n-drawer {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    z-index: 1001;
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border-left: 1px solid var(--border);
    box-shadow: var(--shadow-lg);
}
.n-drawer__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border);
}
.n-drawer__heading {
    min-width: 0;
}
.n-drawer__head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.01em;
    color: var(--text);
}
.n-drawer__sub {
    display: block;
    margin-top: 2px;
    font-size: 12.5px;
    color: var(--text-3);
}
.n-drawer__x {
    width: 38px;
    height: 38px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-2);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    cursor: pointer;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease,
        color 0.15s ease;
}
.n-drawer__x:hover {
    background: var(--surface-3);
    border-color: var(--text-3);
    color: var(--text);
}
.n-drawer__x:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-drawer__body {
    flex: 1;
    overflow-y: auto;
    padding: 22px 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    color: var(--text);
}
.n-drawer__foot {
    display: flex;
    gap: 10px;
    padding: 16px 20px;
    border-top: 1px solid var(--border);
}
.n-fade-enter-active,
.n-fade-leave-active {
    transition: opacity 0.18s;
}
.n-fade-enter-from,
.n-fade-leave-to {
    opacity: 0;
}
.n-slide-enter-active,
.n-slide-leave-active {
    transition: transform 0.24s cubic-bezier(0.4, 0, 0.2, 1);
}
.n-slide-enter-from,
.n-slide-leave-to {
    transform: translateX(100%);
}
/* Appear/disappear without the slide for users who ask for reduced motion. */
@media (prefers-reduced-motion: reduce) {
    .n-slide-enter-active,
    .n-slide-leave-active {
        transition: none;
    }
}
</style>
