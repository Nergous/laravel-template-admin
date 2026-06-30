// useScrollLock — lock <body> scroll while `isActive` is truthy, restoring the
// previous overflow on release. Ref-counted across instances so stacked overlays
// (e.g. a modal opened over a drawer) don't unlock the page too early.
// Usage:
//   useScrollLock(() => props.modelValue)
import { watch, onMounted, onBeforeUnmount } from "vue";

let locks = 0;
let prevOverflow = "";

function lock() {
    if (locks === 0) {
        prevOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
    }
    locks++;
}
function unlock() {
    if (locks === 0) return;
    locks--;
    if (locks === 0) document.body.style.overflow = prevOverflow;
}

export function useScrollLock(isActive) {
    const active =
        typeof isActive === "function" ? isActive : () => isActive?.value;
    // Per-instance flag so an instance contributes at most one lock to the count.
    let held = false;
    function apply(on) {
        if (on && !held) {
            lock();
            held = true;
        } else if (!on && held) {
            unlock();
            held = false;
        }
    }
    watch(active, (v) => apply(!!v), { flush: "post" });
    onMounted(() => {
        if (active()) apply(true);
    });
    onBeforeUnmount(() => apply(false));
}
