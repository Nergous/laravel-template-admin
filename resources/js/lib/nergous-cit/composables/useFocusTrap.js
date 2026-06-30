// useFocusTrap — keeps keyboard focus inside an open dialog container and restores it on close.
// Usage:
//   const dialogRef = ref(null)
//   useFocusTrap(dialogRef, () => props.modelValue)
// Args:
//   containerRef — ref to the dialog DOM element (or component with $el).
//   isActive — reactive source (ref / getter) that is truthy while the dialog is open.
// Behaviour:
//   • on activation: remembers document.activeElement, focuses the first focusable
//     element inside the container (or the container itself with tabindex=-1).
//   • Tab / Shift+Tab cycle focus within the container.
//   • on deactivation / unmount: restores focus to the previously active element
//     and detaches its listeners. No external dependencies.
import { watch, onMounted, onBeforeUnmount, nextTick } from "vue";

// Selector matching every natively focusable element Tab should be able to reach.
const FOCUSABLE = [
    "a[href]",
    "button:not([disabled])",
    "textarea:not([disabled])",
    "input:not([disabled])",
    "select:not([disabled])",
    "audio[controls]",
    "video[controls]",
    '[contenteditable]:not([contenteditable="false"])',
    "summary",
    "iframe",
    '[tabindex]:not([tabindex="-1"])',
].join(",");

// Shared stack of active traps so nested overlays cooperate: only the topmost
// (most recently activated) trap handles Tab; outer traps stand down until it
// deactivates. Without this, a modal opened inside a drawer fights the drawer's
// trap and focus bounces between them.
const trapStack = [];

// Resolve a container argument (ref / getter / component instance) to a raw DOM element.
function resolve(target) {
    const el = typeof target === "function" ? target() : target?.value;
    // unwrap component instances exposing $el
    return el && el.$el ? el.$el : el || null;
}

// Collect the visible focusable elements inside `container`, in DOM order.
function focusableWithin(container) {
    if (!container) return [];
    return Array.from(container.querySelectorAll(FOCUSABLE)).filter(
        (el) =>
            el.offsetWidth > 0 ||
            el.offsetHeight > 0 ||
            el === document.activeElement,
    );
}

export function useFocusTrap(containerRef, isActive) {
    const active =
        typeof isActive === "function" ? isActive : () => isActive?.value;
    let previouslyFocused = null;
    // Identity token for this trap instance on the shared stack.
    const trapId = {};

    // Trap Tab / Shift+Tab so focus wraps within the container instead of escaping it.
    function onKeydown(e) {
        if (e.key !== "Tab") return;
        // Stand down while a trap stacked above us (e.g. a modal over this drawer) is open.
        if (trapStack[trapStack.length - 1] !== trapId) return;
        const container = resolve(containerRef);
        if (!container) return;
        const items = focusableWithin(container);
        if (!items.length) {
            // nothing focusable: keep focus on the container itself
            e.preventDefault();
            container.focus();
            return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        const current = document.activeElement;
        const inside = container.contains(current);
        if (e.shiftKey) {
            if (current === first || !inside) {
                e.preventDefault();
                last.focus();
            }
        } else if (current === last || !inside) {
            e.preventDefault();
            first.focus();
        }
    }

    // Open the trap: remember current focus, start listening for Tab, move focus inside.
    function activate() {
        previouslyFocused =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        if (!trapStack.includes(trapId)) trapStack.push(trapId);
        document.addEventListener("keydown", onKeydown, true);
        nextTick(() => {
            const container = resolve(containerRef);
            if (!container) return;
            const items = focusableWithin(container);
            if (items.length) {
                items[0].focus();
            } else {
                if (!container.hasAttribute("tabindex"))
                    container.setAttribute("tabindex", "-1");
                container.focus();
            }
        });
    }

    // Close the trap: stop listening and restore focus to where it was before opening.
    function deactivate() {
        document.removeEventListener("keydown", onKeydown, true);
        const idx = trapStack.indexOf(trapId);
        if (idx !== -1) trapStack.splice(idx, 1);
        if (
            previouslyFocused &&
            typeof previouslyFocused.focus === "function"
        ) {
            previouslyFocused.focus();
        }
        previouslyFocused = null;
    }

    // React to the dialog opening/closing (post-flush so the DOM is already updated).
    watch(
        active,
        (open) => {
            if (open) activate();
            else deactivate();
        },
        { flush: "post" },
    );

    // Handle the case where the dialog is already open when this component mounts.
    onMounted(() => {
        if (active()) activate();
    });

    // Safety net: always release the trap if the host component is destroyed while open.
    onBeforeUnmount(() => {
        deactivate();
    });
}
