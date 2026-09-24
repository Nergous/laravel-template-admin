import { onBeforeUnmount, onMounted } from "vue";
import { router } from "@inertiajs/vue3";

const MESSAGE = "Есть несохранённые изменения. Уйти со страницы?";

/**
 * Ask before leaving a page with unsaved form changes: Inertia navigation and
 * tab close/reload. Submissions of the form itself (non-GET visits) pass.
 */
export function useUnsavedGuard(isDirty: () => boolean) {
    const offBefore = router.on("before", (event) => {
        if (event.detail.visit.method !== "get" || !isDirty()) return;
        if (!window.confirm(MESSAGE)) return false;
    });

    function onBeforeUnload(e: BeforeUnloadEvent) {
        if (!isDirty()) return;
        e.preventDefault();
        e.returnValue = "";
    }

    onMounted(() => window.addEventListener("beforeunload", onBeforeUnload));
    onBeforeUnmount(() => {
        offBefore();
        window.removeEventListener("beforeunload", onBeforeUnload);
    });
}
