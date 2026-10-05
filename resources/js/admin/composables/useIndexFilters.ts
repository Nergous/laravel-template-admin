import { onBeforeUnmount } from "vue";
import { router } from "@inertiajs/vue3";

/** Reload an Inertia index with current filters, debounced search, and sorting. */
export function useIndexFilters(
    url: string,
    params: () => Record<string, string | number | boolean | null | undefined>,
    { debounce = 300 }: { debounce?: number } = {},
) {
    let timer: ReturnType<typeof setTimeout> | null = null;

    function cancelPendingSearch() {
        if (timer) clearTimeout(timer);
        timer = null;
    }

    // Every reload supersedes a search still waiting for its debounce: it would
    // otherwise fire later and reset the page, sort or filter just chosen.
    function reload(
        extra: Record<
            string,
            string | number | boolean | null | undefined
        > = {},
    ) {
        cancelPendingSearch();
        router.get(
            url,
            { ...params(), ...extra },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function onSearch() {
        cancelPendingSearch();
        timer = setTimeout(() => reload({ page: 1 }), debounce);
    }

    function onSort({ key, dir }: { key: string; dir: "asc" | "desc" }) {
        reload({ sort: key, direction: dir, page: 1 });
    }

    // Prevent a pending search from reloading after navigation.
    onBeforeUnmount(cancelPendingSearch);

    return { reload, onSearch, onSort };
}
