import { onBeforeUnmount } from "vue";
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

/** Toast server errors a page cannot show inline: business rules, expired sessions, network failures. */
export function useServerErrors() {
    const toast = useToast();

    const offError = router.on("error", (event) => {
        const errors = event.detail.errors as Record<string, string>;
        for (const key of NON_FIELD_KEYS) {
            if (errors[key]) toast.error("Действие не выполнено", errors[key]);
        }
    });

    const offHttp = router.on("httpException", (event) => {
        const status = event.detail.response.status;
        if (status === 419) {
            toast.error(
                "Сессия истекла",
                "Обновите страницу и повторите действие.",
            );
            return false;
        }
        if (status === 403) {
            toast.error("Недостаточно прав", "Это действие вам недоступно.");
            return false;
        }
    });

    const offNetwork = router.on("networkError", () => {
        toast.error("Нет связи с сервером", "Проверьте соединение.");
    });

    onBeforeUnmount(() => {
        offError();
        offHttp();
        offNetwork();
    });
}
