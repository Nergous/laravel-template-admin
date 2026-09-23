import { onBeforeUnmount } from "vue";
import { router } from "@inertiajs/vue3";

/** Reload an Inertia index with current filters, debounced search, and sorting. */
export function useIndexFilters(
    url: string,
    params: () => Record<string, string | number | boolean | null | undefined>,
    { debounce = 300 }: { debounce?: number } = {},
) {
    let timer: ReturnType<typeof setTimeout> | null = null;

    function reload(
        extra: Record<
            string,
            string | number | boolean | null | undefined
        > = {},
    ) {
        router.get(
            url,
            { ...params(), ...extra },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function onSearch() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => reload({ page: 1 }), debounce);
    }

    function onSort({ key, dir }: { key: string; dir: "asc" | "desc" }) {
        reload({ sort: key, direction: dir, page: 1 });
    }

    // Prevent a pending search from reloading after navigation.
    onBeforeUnmount(() => {
        if (timer) clearTimeout(timer);
    });

    return { reload, onSearch, onSort };
}
