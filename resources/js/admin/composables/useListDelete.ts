import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import type { useBulkSelection } from "@/admin/composables/useBulkSelection";
import { useConfirm } from "nergous-ui-vue";

/**
 * Single and bulk deletion of a list page with REST routes
 * DELETE {baseUrl}/{id} and DELETE {baseUrl}/bulk. State for two
 * NConfirmDialog dialogs: one for the row (del), one for the selection (bulk*).
 */
export function useListDelete<T extends { id: number }>(
    baseUrl: string,
    selection: Pick<
        ReturnType<typeof useBulkSelection>,
        "selectedCount" | "selectionPayload" | "clearSelection"
    >,
) {
    const del = useConfirm<T>();

    function confirmDelete() {
        if (!del.payload) return;
        del.loading = true;
        router.delete(`${baseUrl}/${del.payload.id}`, {
            preserveScroll: true,
            onFinish: () => del.close(),
        });
    }

    const bulkOpen = ref(false);
    const bulkLoading = ref(false);

    function askBulkDelete() {
        if (selection.selectedCount.value > 0) bulkOpen.value = true;
    }

    function confirmBulkDelete() {
        bulkLoading.value = true;
        router.delete(`${baseUrl}/bulk`, {
            data: selection.selectionPayload(),
            preserveScroll: true,
            onSuccess: selection.clearSelection,
            onFinish: () => {
                bulkLoading.value = false;
                bulkOpen.value = false;
            },
        });
    }

    return {
        del,
        confirmDelete,
        bulkOpen,
        bulkLoading,
        askBulkDelete,
        confirmBulkDelete,
    };
}
