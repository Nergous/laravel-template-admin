import { computed, ref } from "vue";
import type { MediaItem } from "@/admin/types";
import type { MediaSelection } from "@/admin/composables/useMediaSelection";

/**
 * Dragging cards (or the whole selection) onto a folder chip, tile or row.
 * take() hands the dragged files to the drop handler and ends the drag.
 */
export function useMediaDrag(
    selection: MediaSelection,
    source: {
        canDrag: () => boolean;
        visibleIds: () => number[];
        total: () => number;
    },
) {
    const dragIds = ref<number[]>([]);
    // Dragging while "all matching" is chosen moves every matching file.
    const dragAll = ref(false);
    const dragCount = computed(() =>
        dragAll.value ? source.total() : dragIds.value.length,
    );
    const dropTarget = ref<string | null>(null);

    function onFolderDragEnter(name: string) {
        if (dragIds.value.length) dropTarget.value = name;
    }
    function setDropTarget(name: string) {
        dropTarget.value = name;
    }
    function onFolderDragLeave(name: string) {
        if (dropTarget.value === name) dropTarget.value = null;
    }
    function onCardDragStart(e: DragEvent, m: MediaItem) {
        if (!source.canDrag()) return;
        const all = selection.allMatching.value;
        const picked = selection.selected.value;
        dragAll.value = all;
        dragIds.value = all
            ? source.visibleIds()
            : picked.has(m.id)
              ? [...picked]
              : [m.id];
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = "move";
            e.dataTransfer.setData("text/plain", dragIds.value.join(","));
        }
    }
    function onCardDragEnd() {
        dragIds.value = [];
        dragAll.value = false;
        dropTarget.value = null;
    }
    function take() {
        const dropped = { ids: dragIds.value, all: dragAll.value };
        onCardDragEnd();
        return dropped;
    }

    return {
        dragIds,
        dragCount,
        dropTarget,
        onFolderDragEnter,
        onFolderDragLeave,
        setDropTarget,
        onCardDragStart,
        onCardDragEnd,
        take,
    };
}

export type MediaDrag = ReturnType<typeof useMediaDrag>;
