import { onMounted, onUnmounted } from "vue";

// Use physical letter/digit keys so shortcuts also work under Cyrillic layouts.
function keyName(e: KeyboardEvent): string {
    if (/^Key[A-Z]$/.test(e.code)) return e.code.slice(3).toLowerCase();
    if (/^Digit[0-9]$/.test(e.code)) return e.code.slice(5);
    return e.key.toLowerCase();
}

/** Register window shortcuts for a component's mounted lifetime. */
export function useHotkeys(
    map: Record<string, (event: KeyboardEvent) => void>,
): void {
    function handler(e: KeyboardEvent) {
        const mod = e.metaKey || e.ctrlKey;
        const combo = (mod ? "mod+" : "") + keyName(e);
        if (map[combo]) {
            e.preventDefault();
            map[combo](e);
        }
    }
    onMounted(() => window.addEventListener("keydown", handler));
    onUnmounted(() => window.removeEventListener("keydown", handler));
}
