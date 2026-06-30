// useInert — makes the page behind an open overlay `inert` (non-interactive and
// removed from the accessibility tree) so a screen-reader virtual cursor or Tab
// can't wander into the background while a dialog is open. Belt-and-suspenders
// alongside aria-modal, which modern SRs honour only unreliably.
//
// Ref-counted across instances so stacked overlays (a modal over a drawer) keep
// the background inert until the last one closes. App-agnostic: it never hardcodes
// an app-root id — it inerts every <body> child EXCEPT those marked [data-overlay]
// (the teleported overlays and the toaster), so those stay interactive.
//
// IMPORTANT: call this BEFORE useFocusTrap in the host component. Both are
// flush:"post" watchers firing in creation order; releasing inert first means the
// trap can restore focus to a trigger that is no longer inert.
//
// Usage:
//   useInert(() => props.modelValue)
import { watch, onMounted, onBeforeUnmount } from "vue";

let count = 0;
let inerted = []; // exactly the elements we set inert on, so we restore only those

function applyInert() {
    inerted = [];
    for (const el of Array.from(document.body.children)) {
        if (el.hasAttribute("data-overlay")) continue;
        if (el.inert) continue; // don't touch (or later clear) pre-inert elements
        el.inert = true;
        inerted.push(el);
    }
}
function releaseInert() {
    for (const el of inerted) el.inert = false;
    inerted = [];
}

function acquire() {
    if (count === 0) applyInert();
    count++;
}
function release() {
    if (count === 0) return;
    count--;
    if (count === 0) releaseInert();
}

export function useInert(isActive) {
    const active =
        typeof isActive === "function" ? isActive : () => isActive?.value;
    // Per-instance flag so an instance contributes at most one to the count.
    let held = false;
    function apply(on) {
        if (on && !held) {
            acquire();
            held = true;
        } else if (!on && held) {
            release();
            held = false;
        }
    }
    watch(active, (v) => apply(!!v), { flush: "post" });
    onMounted(() => {
        if (active()) apply(true);
    });
    onBeforeUnmount(() => apply(false));
}
