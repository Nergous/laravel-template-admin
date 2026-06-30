<script setup>
// NToaster — toast notifications container. Mount once near the app root;
// push messages from anywhere via useToast() (success / error / warning / info).
// Locale-agnostic: pass regionLabel / dismissLabel (English defaults).
import { useToast } from "../../composables/useToast.js";
import NIcon from "../primitives/NIcon.vue";

defineProps({
    regionLabel: { type: String, default: "Notifications" },
    dismissLabel: { type: String, default: "Dismiss" },
});

const { toasts, dismiss, pauseAll, resumeAll } = useToast();
// Same danger/warn glyph as NAlert: a warning triangle (tone color carries severity).
// No "trash" for danger (that reads as delete) and no "bolt" for warn.
const ICONS = {
    ok: "check",
    info: "bell",
    warn: "alert-triangle",
    danger: "alert-triangle",
};
// danger/warn announce assertively; ok/info politely (mirrors NAlert).
const roleFor = (tone) =>
    tone === "danger" || tone === "warn" ? "alert" : "status";
</script>

<template>
    <Teleport to="body">
        <div
            class="n-toaster"
            data-overlay
            role="region"
            :aria-label="regionLabel"
            @mouseenter="pauseAll"
            @mouseleave="resumeAll"
            @focusin="pauseAll"
            @focusout="resumeAll"
        >
            <TransitionGroup name="n-toast">
                <div
                    v-for="t in toasts"
                    :key="t.id"
                    class="n-toast"
                    :class="'n-toast--' + t.tone"
                >
                    <span class="n-toast__icon" aria-hidden="true"
                        ><NIcon :name="ICONS[t.tone] || 'check'" :size="15"
                    /></span>
                    <div
                        class="n-toast__body"
                        :role="roleFor(t.tone)"
                        aria-atomic="true"
                    >
                        <div v-if="t.title" class="n-toast__title">
                            {{ t.title }}
                        </div>
                        <div v-if="t.msg" class="n-toast__msg">{{ t.msg }}</div>
                    </div>
                    <button
                        type="button"
                        class="n-toast__x"
                        :aria-label="dismissLabel"
                        @click="dismiss(t.id)"
                    >
                        <NIcon name="x" :size="14" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>

<style scoped>
.n-toaster {
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 1100;
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-items: flex-end;
    /* let clicks pass through the gaps; toasts themselves stay interactive */
    pointer-events: none;
}
.n-toast {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    width: 330px;
    padding: 13px 14px;
    background: var(--surface);
    border: 1px solid var(--border-2);
    border-left: 3px solid var(--accent);
    border-radius: 12px;
    box-shadow: var(--shadow-lg);
    pointer-events: auto;
}
.n-toast--ok {
    border-left-color: var(--ok);
}
.n-toast--ok .n-toast__icon {
    color: var(--ok);
    background: var(--ok-bg);
}
.n-toast--info {
    border-left-color: var(--info);
}
.n-toast--info .n-toast__icon {
    color: var(--info);
    background: var(--info-bg);
}
.n-toast--warn {
    border-left-color: var(--warn);
}
.n-toast--warn .n-toast__icon {
    color: var(--warn);
    background: var(--warn-bg);
}
.n-toast--danger {
    border-left-color: var(--danger);
}
.n-toast--danger .n-toast__icon {
    color: var(--danger);
    background: var(--danger-bg);
}
.n-toast__icon {
    width: 28px;
    height: 28px;
    border-radius: var(--radius-sm);
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--accent-soft);
    color: var(--accent);
}
.n-toast__body {
    flex: 1;
    min-width: 0;
}
.n-toast__title {
    font-weight: 700;
    font-size: 13.5px;
    color: var(--text);
}
.n-toast__msg {
    font-size: 12.5px;
    color: var(--text-3);
    margin-top: 1px;
}
.n-toast__x {
    width: 26px;
    height: 26px;
    border-radius: 6px;
    border: none;
    background: transparent;
    color: var(--text-3);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex: none;
}
.n-toast__x:hover {
    background: var(--surface-3);
}
.n-toast__x:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.n-toast-enter-active {
    transition: all 0.25s cubic-bezier(0.34, 1.3, 0.6, 1);
}
.n-toast-leave-active {
    transition: all 0.2s ease;
    position: absolute;
}
.n-toast-enter-from {
    opacity: 0;
    transform: translateY(12px) scale(0.96);
}
.n-toast-leave-to {
    opacity: 0;
    transform: translateX(20px);
}
/* Fade only — no slide/scale — for users who ask for reduced motion. */
@media (prefers-reduced-motion: reduce) {
    .n-toast-enter-from,
    .n-toast-leave-to {
        transform: none;
    }
}
</style>
