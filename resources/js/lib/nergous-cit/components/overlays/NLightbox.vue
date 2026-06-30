<script setup>
// NLightbox — fullscreen image viewer overlay. Dialog semantics with focus trap,
// scroll lock and Escape/Arrow keys. Presentation-only: pass items + v-model:index.
import { ref, watch, onUnmounted } from "vue";
import NIcon from "../primitives/NIcon.vue";
import { useFocusTrap } from "../../composables/useFocusTrap.js";
import { useScrollLock } from "../../composables/useScrollLock.js";
import { useDismiss } from "../../composables/useDismiss.js";
import { useInert } from "../../composables/useInert.js";

const props = defineProps({
    // [{ url, caption }] — caption doubles as the image alt text.
    items: { type: Array, default: () => [] },
    // Current item index; -1 = closed. Use with v-model:index.
    index: { type: Number, default: -1 },
    // Accessible labels — English defaults; pass localized strings at the call site.
    dialogLabel: { type: String, default: "Image viewer" },
    closeLabel: { type: String, default: "Close" },
    prevLabel: { type: String, default: "Previous" },
    nextLabel: { type: String, default: "Next" },
});
const emit = defineEmits(["update:index"]);

const root = ref(null);
const isOpen = () => props.index >= 0 && !!props.items[props.index];

function close() {
    emit("update:index", -1);
}
function prev() {
    if (props.index > 0) emit("update:index", props.index - 1);
}
function next() {
    if (props.index < props.items.length - 1)
        emit("update:index", props.index + 1);
}

// Arrow navigation. Escape is handled by useDismiss (topmost-overlay-only),
// Tab cycling and focus return by useFocusTrap.
function onKey(e) {
    if (props.index < 0) return;
    if (e.key === "ArrowLeft") prev();
    else if (e.key === "ArrowRight") next();
}
watch(
    () => props.index,
    (i) => {
        if (i >= 0) window.addEventListener("keydown", onKey);
        else window.removeEventListener("keydown", onKey);
    },
);
onUnmounted(() => window.removeEventListener("keydown", onKey));

// Before useFocusTrap so inert is released before focus returns to the trigger.
useInert(isOpen);
useFocusTrap(root, isOpen);
useScrollLock(isOpen);
// Escape closes only the topmost overlay (shared dismiss stack), not the whole stack.
useDismiss(isOpen, close);
</script>

<template>
    <Teleport to="body">
        <Transition name="n-lb-fade">
            <div
                v-if="index >= 0 && items[index]"
                ref="root"
                class="n-lb"
                data-overlay
                role="dialog"
                aria-modal="true"
                :aria-label="dialogLabel"
                @click.self="close"
            >
                <button
                    type="button"
                    class="n-lb__btn n-lb__x"
                    :aria-label="closeLabel"
                    @click="close"
                >
                    <NIcon name="x" :size="22" />
                </button>
                <button
                    v-if="index > 0"
                    type="button"
                    class="n-lb__btn n-lb__prev"
                    :aria-label="prevLabel"
                    @click="prev"
                >
                    <NIcon name="chevron-left" :size="26" />
                </button>
                <img
                    class="n-lb__img"
                    :src="items[index].url"
                    :alt="items[index].caption || ''"
                />
                <button
                    v-if="index < items.length - 1"
                    type="button"
                    class="n-lb__btn n-lb__next"
                    :aria-label="nextLabel"
                    @click="next"
                >
                    <NIcon name="chevron-right" :size="26" />
                </button>
                <div v-if="items[index].caption" class="n-lb__caption">
                    {{ items[index].caption }}
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.n-lb {
    position: fixed;
    inset: 0;
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--overlay);
    backdrop-filter: blur(3px);
    padding: 40px;
}
.n-lb__img {
    max-width: 90vw;
    max-height: 82vh;
    border-radius: 10px;
    box-shadow: var(--shadow-lg);
}
.n-lb__btn {
    position: absolute;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: none;
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
    cursor: pointer;
}
.n-lb__btn:hover {
    background: rgba(255, 255, 255, 0.22);
}
.n-lb__btn:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-lb__x {
    top: 20px;
    right: 20px;
}
.n-lb__prev {
    left: 20px;
}
.n-lb__next {
    right: 20px;
}
.n-lb__caption {
    position: absolute;
    bottom: 18px;
    left: 0;
    right: 0;
    text-align: center;
    color: #fff;
    font-size: 13px;
    opacity: 0.85;
}
.n-lb-fade-enter-active,
.n-lb-fade-leave-active {
    transition: opacity 0.18s;
}
.n-lb-fade-enter-from,
.n-lb-fade-leave-to {
    opacity: 0;
}
</style>
