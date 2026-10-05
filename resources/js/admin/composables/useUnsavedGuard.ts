import { onBeforeUnmount, onMounted } from "vue";
import { router } from "@inertiajs/vue3";
import { isRedirectingToLogin } from "@/lib/api";

const MESSAGE = "Есть несохранённые изменения. Уйти со страницы?";

/** URL without the hash: in-page anchor jumps are not navigation. */
function withoutHash(url: string): string {
    const i = url.indexOf("#");
    return i === -1 ? url : url.slice(0, i);
}

/**
 * Ask before leaving a page with unsaved form changes: Inertia navigation,
 * browser Back/Forward and tab close/reload. Submissions of the form itself
 * (non-GET visits) and the forced trip to the login page pass.
 */
export function useUnsavedGuard(isDirty: () => boolean) {
    const off: (() => void)[] = [];
    // History entry of this page, put back when the user stays after Back/Forward.
    let pageState: unknown = null;
    let pageUrl = "";

    function rememberEntry() {
        pageState = window.history.state;
        pageUrl = window.location.href;
    }

    // Runs in the capture phase, before Inertia's own popstate listener on
    // window, so a refusal can stop the page swap.
    function onPopstate(event: PopStateEvent) {
        if (!isDirty() || isRedirectingToLogin()) return;
        if (withoutHash(window.location.href) === withoutHash(pageUrl)) return;
        if (window.confirm(MESSAGE)) return;
        event.stopImmediatePropagation();
        // The browser has already moved to the other entry: return the address
        // bar to this page (the forward part of the history is dropped).
        window.history.pushState(pageState, "", pageUrl);
    }

    function onBeforeUnload(e: BeforeUnloadEvent) {
        if (!isDirty() || isRedirectingToLogin()) return;
        e.preventDefault();
        e.returnValue = "";
    }

    onMounted(() => {
        rememberEntry();
        off.push(
            router.on("before", (event) => {
                if (event.detail.visit.method !== "get" || !isDirty()) return;
                if (isRedirectingToLogin()) return;
                if (!window.confirm(MESSAGE)) return false;
            }),
            // Visits that keep this page (validation errors) refresh its entry.
            router.on("navigate", rememberEntry),
        );
        window.addEventListener("popstate", onPopstate, { capture: true });
        window.addEventListener("beforeunload", onBeforeUnload);
    });

    onBeforeUnmount(() => {
        for (const unsubscribe of off.splice(0)) unsubscribe();
        window.removeEventListener("popstate", onPopstate, { capture: true });
        window.removeEventListener("beforeunload", onBeforeUnload);
    });
}
