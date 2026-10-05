import { computed, ref, watch } from "vue";

type Filters = Record<string, string>;

/** Flat bulk payload: picked ids, or { all: true } plus the list filters. */
export type BulkPayload =
    { ids: number[] } | { all: true; [filter: string]: string | true };

/**
 * Row selection for a server-paginated list with bulk actions: rows picked on
 * the current page, or "all matching" — every row that matches the filters,
 * sent to the server as the flat payload { all: true, ...filters } (filter
 * keys are the list's query parameters) instead of ids.
 *
 * Bind to NDataTable: :selected="selected", @update:selected="updateSelected",
 * :total and v-model:all-matching="allMatchingSelected". The table offers
 * "select all N" once the page is selected and shows every row as selected
 * in that mode.
 *
 * The selection never outlives what is on screen: a change of the filters
 * drops it completely, and rows that left the page (page, sort or page-size
 * change, deletion elsewhere) are dropped from the picked ids. "All matching"
 * survives paging and sorting because it is defined by the filters alone.
 */
export function useBulkSelection(
    pageIds: () => number[],
    total: () => number,
    filters: () => Filters,
) {
    const selected = ref<number[]>([]);
    const allMatchingSelected = ref(false);

    const selectedCount = computed(() =>
        allMatchingSelected.value ? total() : selected.value.length,
    );

    function updateSelected(ids: Array<string | number>) {
        selected.value = ids.map(Number);
    }

    function clearSelection() {
        selected.value = [];
        allMatchingSelected.value = false;
    }

    function selectionPayload(): BulkPayload {
        if (!allMatchingSelected.value) return { ids: selected.value };
        return { all: true, ...filters() };
    }

    // Another filter set is another result set.
    watch(() => JSON.stringify(filters()), clearSelection);

    // Keep only the picked rows that are still on the screen.
    watch(
        () => pageIds().join(","),
        () => {
            if (allMatchingSelected.value) return;
            const visible = new Set(pageIds());
            if (selected.value.some((id) => !visible.has(id))) {
                selected.value = selected.value.filter((id) => visible.has(id));
            }
        },
    );

    return {
        selected,
        allMatchingSelected,
        selectedCount,
        updateSelected,
        clearSelection,
        selectionPayload,
    };
}
