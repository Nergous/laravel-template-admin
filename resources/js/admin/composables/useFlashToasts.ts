import { watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useToast } from "nergous-ui-vue";
import type { SharedProps } from "@/admin/types";

// Flash keys match toast methods; the design-system Tone values describe colors.
const FLASH_KEYS = [
    "success",
    "error",
    "warning",
    "info",
] as const satisfies readonly (keyof SharedProps["flash"])[];

/** Show shared Inertia flash messages as toasts. */
export function useFlashToasts() {
    const page = usePage<SharedProps>();
    const toast = useToast();
    watch(
        () => page.props.flash,
        (flash) => {
            if (!flash) return;
            for (const key of FLASH_KEYS) {
                if (flash[key]) toast[key](flash[key]);
            }
        },
        { immediate: true },
    );
}
