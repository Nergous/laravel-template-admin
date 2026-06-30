/**
 * can(perm) — UI permission check on top of Inertia's shared props (auth.can).
 * For conditional rendering only; the server checks permissions independently (middleware in routes/web.php).
 * Empty/missing perm → true (intentional: for menu items without restrictions).
 */
import { usePage } from "@inertiajs/vue3";

export function can(perm) {
    const list = usePage().props.auth?.can ?? [];
    return !perm || list.includes(perm);
}
