import { onBeforeUnmount, onMounted } from "vue";
import { router } from "@inertiajs/vue3";
import { useToast } from "nergous-ui-vue";

// Error keys that belong to no form field: business rules thrown by services
// (ValidationException::withMessages). Pages do not render them, so they are toasted.
const NON_FIELD_KEYS = [
    "user",
    "role",
    "matrix",
    "permission",
    "session",
    "media",
    "backup",
];

/**
 * Toast server errors a page cannot show inline: business rules, missing rights,
 * network failures. An expired CSRF token needs nothing here: the server answers
 * Inertia visits with a redirect back and a warning flash (bootstrap/app.php).
 */
export function useServerErrors() {
    const toast = useToast();
    const off: (() => void)[] = [];

    onMounted(() => {
        off.push(
            router.on("error", (event) => {
                const errors = event.detail.errors as Record<string, string>;
                for (const key of NON_FIELD_KEYS) {
                    if (errors[key])
                        toast.error("Действие не выполнено", errors[key]);
                }
            }),
            router.on("httpException", (event) => {
                if (event.detail.response.status === 403) {
                    toast.error(
                        "Недостаточно прав",
                        "Это действие вам недоступно.",
                    );
                    return false;
                }
            }),
            router.on("networkError", () => {
                toast.error("Нет связи с сервером", "Проверьте соединение.");
            }),
        );
    });

    onBeforeUnmount(() => {
        for (const unsubscribe of off.splice(0)) unsubscribe();
    });
}
