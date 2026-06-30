// Регистрация глобальных хоткеев. Слушает keydown на window, снимает обработчик
// при размонтировании компонента.
import { onMounted, onUnmounted } from "vue";

// Имя клавиши, независимое от раскладки: буквы/цифры берём из физического code
// (KeyK → "k", Digit1 → "1"), иначе семантический e.key (escape, enter, …).
// Без этого mod+k не ловится под кириллицей (e.key === "л").
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
