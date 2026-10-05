import { computed, ref, watch } from "vue";

/** Media list filters under the index page's query parameter names. */
export type MediaListFilters = {
    search?: string;
    type?: string;
    folder?: string;
    usage?: string;
};

const FILTER_KEYS = ["search", "type", "folder", "usage"] as const;

/** Bulk request selection: the picked ids, or every file matching the filters. */
export type MediaSelectionPayload =
    { ids: number[] } | ({ all: true } & MediaListFilters);

/**
 * The list filters as the index sends them: non-empty values only, so
 * "every matching file" means exactly what the list shows.
 */
export function listFilterParams(filters: MediaListFilters): MediaListFilters {
    const params: MediaListFilters = {};
    for (const key of FILTER_KEYS) {
        const value = filters[key];
        if (value) params[key] = value;
    }
    return params;
}

/**
 * Body of PATCH /admin/media/bulk-folder. The flat filters of "every matching
 * file" already use "folder" (the open folder), so the destination always
 * travels as "target".
 */
export function bulkFolderBody(
    selection: MediaSelectionPayload,
    destination: string,
) {
    return { ...selection, target: destination };
}

/**
 * File selection of the media library: ids picked on the current page, or
 * "all matching" — every file of the list for the current filters, on every
 * page (folders aside), sent to the server as { all: true, ...filters }.
 */
export function useMediaSelection(source: {
    visibleIds: () => number[];
    total: () => number;
    filters: () => MediaListFilters;
    /** The server page: a new one (filter, sort, page) drops the picked ids. */
    page: () => unknown;
}) {
    // Reassign the Set to trigger Vue updates.
    const selected = ref(new Set<number>());
    const allMatching = ref(false);

    // A filter, sort or page change brings a new server page: drop the
    // selection, so bulk actions never touch files the user can't see.
    watch(source.page, () => {
        selected.value = new Set();
    });
    // Another filter or folder means another set of files: drop the "all" choice.
    watch(
        () => JSON.stringify(source.filters()),
        () => {
            allMatching.value = false;
        },
    );

    function isSelected(id: number) {
        return allMatching.value || selected.value.has(id);
    }
    function toggleSelect(id: number) {
        // Unticking a file leaves "all" for the rest of this page, like the tables do.
        if (allMatching.value) {
            allMatching.value = false;
            selected.value = new Set(
                source.visibleIds().filter((x) => x !== id),
            );
            return;
        }
        const next = new Set(selected.value);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        selected.value = next;
    }
    const selectedCount = computed(() =>
        allMatching.value ? source.total() : selected.value.size,
    );
    // Offered once something is picked and the list holds more than that.
    const canSelectAllMatching = computed(
        () =>
            !allMatching.value &&
            selected.value.size > 0 &&
            source.total() > selected.value.size,
    );
    function selectAllMatching() {
        allMatching.value = true;
        selected.value = new Set();
    }
    function selectionPayload(): MediaSelectionPayload {
        if (!allMatching.value) return { ids: [...selected.value] };
        return { all: true, ...listFilterParams(source.filters()) };
    }
    const allVisibleSelected = computed(() => {
        const ids = source.visibleIds();
        return (
            ids.length > 0 &&
            (allMatching.value || ids.every((id) => selected.value.has(id)))
        );
    });
    // Partial selection — drives the checkbox's indeterminate ("mixed") state.
    const someVisibleSelected = computed(
        () =>
            !allVisibleSelected.value &&
            source.visibleIds().some((id) => selected.value.has(id)),
    );
    function toggleAllVisible() {
        if (allMatching.value) {
            clearSelection();
            return;
        }
        const next = new Set(selected.value);
        const ids = source.visibleIds();
        if (allVisibleSelected.value) {
            for (const id of ids) next.delete(id);
        } else {
            for (const id of ids) next.add(id);
        }
        selected.value = next;
    }
    function clearSelection() {
        selected.value = new Set();
        allMatching.value = false;
    }
    // Remove ids from the selection (after deleting the corresponding rows).
    function deselect(ids: number[]) {
        if (!selected.value.size) return;
        const drop = new Set(ids);
        selected.value = new Set(
            [...selected.value].filter((id) => !drop.has(id)),
        );
    }

    return {
        selected,
        allMatching,
        isSelected,
        toggleSelect,
        selectedCount,
        canSelectAllMatching,
        selectAllMatching,
        selectionPayload,
        allVisibleSelected,
        someVisibleSelected,
        toggleAllVisible,
        clearSelection,
        deselect,
    };
}

export type MediaSelection = ReturnType<typeof useMediaSelection>;
