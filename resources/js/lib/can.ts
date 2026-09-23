import { usePage } from "@inertiajs/vue3";
import type { SharedProps } from "@/admin/types";

/** Check a shared permission for UI visibility; the server enforces access. */
export function can(perm?: string | null): boolean {
    const list = usePage<SharedProps>().props.auth?.can ?? [];
    return !perm || list.includes(perm);
}
