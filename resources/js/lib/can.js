/**
 * can(perm) — UI-проверка прав поверх шаренных пропсов Inertia (auth.can).
 * Только для условного рендера; сервер проверяет права независимо (middleware в routes/web.php).
 * Пустой/отсутствующий perm → true (намеренно: для пунктов меню без ограничений).
 */
import { usePage } from "@inertiajs/vue3";

export function can(perm) {
    const list = usePage().props.auth?.can ?? [];
    return !perm || list.includes(perm);
}
