/**
 * Состояние модалки подтверждения (ConfirmModal).
 * reactive → на странице доступ без `.value`:
 *   const del = useConfirm();
 *   del.ask(row);     // открыть с данными строки
 *   del.payload?.id   // данные текущего подтверждения
 *   del.loading       // индикатор на кнопке подтверждения (:loading)
 *   del.close();      // закрыть (сбрасывает и loading)
 *
 * Типичный поток удаления:
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
