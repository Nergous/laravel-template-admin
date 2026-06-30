// useScrollSpy — track which section of a scroll container is in view and
// smooth-scroll to a section on demand. Sections are marked in the DOM with a
// `data-spy="<value>"` attribute on direct children of the container (their
// `offsetTop` must be relative to it, so the container needs `position:
// relative`). Backs NAnchoredForm but is reusable for any anchored long page.
//
// Usage:
//   const scrollEl = ref(null)
//   const { active, scrollTo } = useScrollSpy(scrollEl, { offset: 16 })
//   // active.value → the data-spy string of the topmost in-view section
//   // scrollTo('seo') → smooth-scroll that section to the top (respects
//   //   prefers-reduced-motion)
//
// `active` is always the raw `data-spy` string; callers that key off non-string
// values should compare with String(value).
import { ref, watch, onMounted, onBeforeUnmount } from "vue";

export function useScrollSpy(container, options = {}) {
    const { offset = 16 } = options;
    const active = ref(null);

    const getContainer = () =>
        typeof container === "function" ? container() : container?.value;
    // `offset` may be a number or a getter (reactive prop) — resolve per call.
    const getOffset = () => (typeof offset === "function" ? offset() : offset);

    function sectionEls() {
        const c = getContainer();
        return c ? Array.from(c.querySelectorAll("[data-spy]")) : [];
    }

    // Topmost section whose start has scrolled past the offset line wins.
    function recompute() {
        const c = getContainer();
        if (!c) return;
        const top = c.scrollTop;
        const off = getOffset();
        let current = null;
        for (const el of sectionEls()) {
            if (el.offsetTop - off <= top + 2) current = el.dataset.spy;
        }
        if (current != null && current !== active.value) active.value = current;
    }

    function scrollTo(value) {
        const c = getContainer();
        if (!c) return;
        const el = sectionEls().find((e) => e.dataset.spy === String(value));
        if (!el) return;
        active.value = el.dataset.spy;
        const reduce = window.matchMedia(
            "(prefers-reduced-motion: reduce)",
        ).matches;
        c.scrollTo({
            top: Math.max(0, el.offsetTop - 10),
            behavior: reduce ? "auto" : "smooth",
        });
    }

    // Re-bind the scroll listener when the container element changes (mount,
    // v-if), keeping at most one listener attached.
    let bound = null;
    function bind() {
        const c = getContainer();
        if (c === bound) return;
        if (bound) bound.removeEventListener("scroll", recompute);
        bound = c;
        if (c) {
            c.addEventListener("scroll", recompute, { passive: true });
            recompute();
        }
    }

    onMounted(bind);
    watch(getContainer, bind);
    onBeforeUnmount(() => {
        if (bound) bound.removeEventListener("scroll", recompute);
        bound = null;
    });

    return { active, scrollTo, recompute };
}
