// Watches Inertia's shared flash (page.props.flash) and shows it as toasts.
import { watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useToast } from "@/lib/nergous-cit/index.js";

// The flash keys match the useToast method names (success/error/warning/info).
const TONES = ["success", "error", "warning", "info"];

export function useFlashToasts() {
    const page = usePage();
    const toast = useToast();
    watch(
        () => page.props.flash,
        (flash) => {
            if (!flash) return;
            for (const tone of TONES) {
                if (flash[tone]) toast[tone](flash[tone]);
            }
        },
        { immediate: true },
    );
}
