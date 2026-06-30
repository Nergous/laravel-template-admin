import { ref, computed } from "vue";

/**
 * useRovingRadio — shared WAI-ARIA radio group behaviour for single-choice
 * button groups (NSegmented, NRadioGroup). It manages a roving tabindex (the
 * group is one Tab stop) and arrow-key navigation where selection follows focus,
 * exactly like native radios.
 *
 * Pass reactive getters (not snapshots) so the composable tracks prop changes.
 *
 * @param {() => Array}      getOptions reactive getter for the options ([{ value, ... }])
 * @param {() => *}          getValue   reactive getter for the current v-model value
 * @param {(value) => void}  onSelect   called with the chosen option's value
 * @returns {{ setBtnRef, selectedIndex, tabStop, select, onKeydown }}
 */
export function useRovingRadio(getOptions, getValue, onSelect) {
    // Collect each option's button element by index, so we can move focus.
    const btns = ref([]);
    const setBtnRef = (i) => (el) => {
        if (el) btns.value[i] = el;
    };

    const selectedIndex = computed(() =>
        getOptions().findIndex((o) => o.value === getValue()),
    );
    // The selected option is the tab stop; if nothing is selected yet, the first
    // one is, so the group stays reachable from the keyboard.
    const tabStop = computed(() =>
        selectedIndex.value >= 0 ? selectedIndex.value : 0,
    );

    // Select the option at index i (no-op if unchanged) and move focus to it.
    function select(i) {
        const opt = getOptions()[i];
        if (!opt) return;
        if (opt.value !== getValue()) onSelect(opt.value);
        btns.value[i]?.focus();
    }

    // Arrow/Home/End navigation; selection follows focus.
    function onKeydown(e) {
        const n = getOptions().length;
        if (!n) return;
        const cur = selectedIndex.value >= 0 ? selectedIndex.value : 0;
        let next;
        switch (e.key) {
            case "ArrowRight":
            case "ArrowDown":
                next = (cur + 1) % n;
                break;
            case "ArrowLeft":
            case "ArrowUp":
                next = (cur - 1 + n) % n;
                break;
            case "Home":
                next = 0;
                break;
            case "End":
                next = n - 1;
                break;
            default:
                return;
        }
        e.preventDefault();
        select(next);
    }

    return { setBtnRef, selectedIndex, tabStop, select, onKeydown };
}
