/**
 * State of the confirmation modal (ConfirmModal).
 * reactive → access on the page without `.value`:
 *   const del = useConfirm();
 *   del.ask(row);     // open with row data
 *   del.payload?.id   // data of the current confirmation
 *   del.loading       // indicator on the confirm button (:loading)
 *   del.close();      // close (also resets loading)
 *
 * Typical deletion flow:
 *   function confirmDelete() {
 *     del.loading = true;
 *     router.delete(url, { onFinish: () => del.close() });
 *   }
 */
import { reactive, ref } from "vue";

export function useConfirm() {
    const open = ref(false);
    const payload = ref(null);
    const loading = ref(false);

    function ask(data = null) {
        payload.value = data;
        open.value = true;
    }

    function close() {
        open.value = false;
        loading.value = false;
    }

    return reactive({ open, payload, loading, ask, close });
}
