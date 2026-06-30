import { onBeforeUnmount } from "vue";
import { router } from "@inertiajs/vue3";

/**
 * Server-side list filters on top of Inertia: debounced search, sorting, pagination.
 *
 * Encapsulates the repetitive reload / onSearch (debounce) / onSort and clearing
 * the timer when leaving the page — what would otherwise be copied into every Index page
 * with a table and search. The filter state itself (search, role, …) stays on the
 * page and arrives here as a snapshot via `params()`.
 *
 * Suits pages with debounced search (Users, Roles). Pages with instant
 * chip filters (ActivityLog) or a client-side filter + polling (Media)
 * don't need this composable — they use a different model.
 *
 * @param {string} url — resource URL, e.g. "/admin/users"
 * @param {() => Record<string, any>} params — snapshot of the current filters,
 *        e.g. () => ({ search: search.value, sort: props.currentSort, ... })
 * @param {{ debounce?: number }} [opts] — search debounce delay (ms, default 300)
 * @returns {{ reload: (extra?: object) => void, onSearch: () => void, onSort: (e: {key: string, dir: string}) => void }}
 */
export function useIndexFilters(url, params, { debounce = 300 } = {}) {
    let timer = null;

    function reload(extra = {}) {
        router.get(
            url,
            { ...params(), ...extra },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function onSearch() {
        clearTimeout(timer);
        timer = setTimeout(() => reload({ page: 1 }), debounce);
    }

    function onSort({ key, dir }) {
        reload({ sort: key, direction: dir, page: 1 });
    }

    // Prevent a pending search from triggering reload() after leaving the page.
    onBeforeUnmount(() => clearTimeout(timer));

    return { reload, onSearch, onSort };
}
