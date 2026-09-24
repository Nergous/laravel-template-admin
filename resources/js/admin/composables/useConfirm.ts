import { reactive, ref } from "vue";

/** Keep confirmation state and payload accessible without ref unwrapping. */
export function useConfirm<T = { id: number; name?: string }>() {
    const open = ref(false);
    const payload = ref<T | null>(null);
    const loading = ref(false);

    function ask(data: T | null = null) {
        payload.value = data;
        open.value = true;
    }

    function close() {
        open.value = false;
        loading.value = false;
    }

    return reactive({ open, payload, loading, ask, close });
}
