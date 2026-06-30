// Registration of global hotkeys. Listens for keydown on window, removes the handler
// when the component unmounts.
import { onMounted, onUnmounted } from "vue";

// Layout-independent key name: letters/digits are taken from the physical code
// (KeyK → "k", Digit1 → "1"), otherwise the semantic e.key (escape, enter, …).
// Without this, mod+k isn't caught under Cyrillic (e.key === "л").
function keyName(e) {
    if (/^Key[A-Z]$/.test(e.code)) return e.code.slice(3).toLowerCase();
    if (/^Digit[0-9]$/.test(e.code)) return e.code.slice(5);
    return e.key.toLowerCase();
}

export function useHotkeys(map) {
    // map: { 'mod+k': () => {...}, 'escape': () => {...} }
    function handler(e) {
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
