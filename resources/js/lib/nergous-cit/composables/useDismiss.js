// useDismiss — coordinates Escape-to-dismiss across stacked overlays so a single
// Escape closes only the topmost open overlay.
// Mirrors the trapStack pattern in useFocusTrap: each active overlay pushes onto a
// shared module-level stack, and only the entry on top reacts to Escape.
// Usage:
//   useDismiss(() => props.modelValue, close)
// Args:
//   isActive  — reactive source (ref / getter) truthy while the overlay is open.
//   onDismiss — called when Escape is pressed and this overlay is the topmost one.
// Behaviour:
//   • on activation: pushes onto the shared stack and listens for Escape.
//   • Escape: only the top-of-stack overlay calls onDismiss + stops the event, so a
//     drawer → modal stack closes just the modal, then the drawer on a second press.
//   • on deactivation / unmount: removes its listener and leaves the stack.
import { watch, onMounted, onBeforeUnmount } from "vue";

// Shared stack of active dismissables, ordered by activation. The last entry is
// the topmost overlay and the only one allowed to handle Escape.
const dismissStack = [];

export function useDismiss(isActive, onDismiss) {
    const active =
        typeof isActive === "function" ? isActive : () => isActive?.value;
    // Identity token for this overlay instance on the shared stack.
    const id = {};

    function onKeydown(e) {
        if (e.key !== "Escape") return;
        if (e.defaultPrevented) return;
        // Stand down unless we're the topmost open overlay.
        if (dismissStack[dismissStack.length - 1] !== id) return;
        e.preventDefault();
        // Halt the cascade so overlays beneath us don't also dismiss.
        e.stopPropagation();
        onDismiss();
    }

    function activate() {
        if (!dismissStack.includes(id)) dismissStack.push(id);
        document.addEventListener("keydown", onKeydown);
    }

    function deactivate() {
        document.removeEventListener("keydown", onKeydown);
        const idx = dismissStack.indexOf(id);
        if (idx !== -1) dismissStack.splice(idx, 1);
    }

    // React to the overlay opening/closing (post-flush so the DOM is settled).
    watch(active, (open) => (open ? activate() : deactivate()), {
        flush: "post",
    });

    // Handle the case where the overlay is already open when this component mounts.
    onMounted(() => {
        if (active()) activate();
    });

    // Safety net: always leave the stack if the host is destroyed while open.
    onBeforeUnmount(deactivate);
}
