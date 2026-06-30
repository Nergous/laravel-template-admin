<script setup>
import { ref, useId } from "vue";
import NIcon from "../primitives/NIcon.vue";
import { useFocusTrap } from "../../composables/useFocusTrap.js";
import { useScrollLock } from "../../composables/useScrollLock.js";
import { useDismiss } from "../../composables/useDismiss.js";
import { useInert } from "../../composables/useInert.js";

// NModal — centered dialog. v-model controls open state; emits `close` on dismiss.
// Props: title, width, closeLabel. Default slot is the body; #footer slot
// receives { close }. Locks body scroll and traps focus while open.
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: "" },
    width: { type: String, default: "460px" },
    closeLabel: { type: String, default: "Close" },
});
const emit = defineEmits(["update:modelValue", "close"]);
function close() {
    emit("update:modelValue", false);
    emit("close");
}

const dialogEl = ref(null);
const titleId = useId();
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
                class="n-modal__overlay"
                data-overlay
                @click.self="close"
            >
                <div
                    ref="dialogEl"
                    class="n-modal"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="title ? titleId : undefined"
                    :style="{ width: 'min(' + width + ', 94vw)' }"
                >
                    <div class="n-modal__head">
                        <h3 v-if="title" :id="titleId">{{ title }}</h3>
                        <button
                            type="button"
                            class="n-modal__x"
                            :aria-label="closeLabel"
                            @click="close"
                        >
                            <NIcon name="x" :size="18" />
                        </button>
                    </div>
                    <div class="n-modal__body"><slot /></div>
                    <div v-if="$slots.footer" class="n-modal__foot">
                        <slot name="footer" :close="close" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.n-modal__overlay {
    position: fixed;
    inset: 0;
    background: var(--overlay);
    backdrop-filter: blur(3px);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.n-modal {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    animation: n-pop 0.16s cubic-bezier(0.4, 0, 0.2, 1);
}
.n-modal__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border);
}
.n-modal__head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--text);
}
.n-modal__x {
    width: 38px;
    height: 38px;
    margin-left: auto;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-2);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex: none;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease,
        color 0.15s ease;
}
.n-modal__x:hover {
    background: var(--surface-3);
    border-color: var(--text-3);
    color: var(--text);
}
.n-modal__x:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-modal__body {
    padding: 20px;
    color: var(--text);
}
.n-modal__foot {
    display: flex;
    gap: 10px;
    padding: 16px 20px;
    border-top: 1px solid var(--border);
}
@keyframes n-pop {
    from {
        opacity: 0;
        transform: translateY(10px) scale(0.97);
    }
    to {
        opacity: 1;
        transform: none;
    }
}
.n-fade-enter-active,
.n-fade-leave-active {
    transition: opacity 0.15s;
}
.n-fade-enter-from,
.n-fade-leave-to {
    opacity: 0;
}
/* Skip the pop (slide/scale) for users who ask for reduced motion. */
@media (prefers-reduced-motion: reduce) {
    .n-modal {
        animation: none;
    }
}
</style>
