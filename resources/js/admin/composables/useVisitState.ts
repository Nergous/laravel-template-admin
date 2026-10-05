import { onBeforeUnmount, onMounted, ref } from "vue";
import { router } from "@inertiajs/vue3";

/** Slow visits only: a fast response must not flash a placeholder. */
const DELAY = 250; // ms

/**
 * Loading state of Inertia visits for the persistent layout.
 *
 * - navigating: a GET visit to another page is taking a while; the layout
 *   shows a skeleton in place of the old page.
 * - refreshing: the same page reloads with new filters, sorting or page
 *   number; the layout dims the current list.
 *
 * Background visits (partial reloads with only, prefetch, hidden progress)
 * change neither.
 */
export function useVisitState() {
    const navigating = ref(false);
    const refreshing = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;
    const offs: Array<() => void> = [];

    function reset() {
        if (timer) clearTimeout(timer);
        timer = null;
        navigating.value = false;
        refreshing.value = false;
    }

    onMounted(() => {
        offs.push(
            router.on("start", (event) => {
                const visit = event.detail.visit;
                if (
                    visit.method !== "get" ||
                    visit.prefetch ||
                    !visit.showProgress ||
                    visit.only.length > 0
                ) {
                    return;
                }
                const samePage =
                    visit.url.pathname === window.location.pathname;
                if (timer) clearTimeout(timer);
                timer = setTimeout(() => {
                    if (samePage) refreshing.value = true;
                    else navigating.value = true;
                }, DELAY);
            }),
            router.on("finish", reset),
        );
    });
    onBeforeUnmount(() => {
        offs.forEach((off) => off());
        reset();
    });

    return { navigating, refreshing };
}
