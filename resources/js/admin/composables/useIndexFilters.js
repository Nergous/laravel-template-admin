import { onBeforeUnmount } from "vue";
import { router } from "@inertiajs/vue3";

/**
 * Серверные фильтры списка поверх Inertia: дебаунс-поиск, сортировка, пагинация.
 *
 * Инкапсулирует повторяющиеся reload / onSearch (debounce) / onSort и очистку
 * таймера при уходе со страницы — то, что иначе копируется в каждую Index-страницу
 * с таблицей и поиском. Само состояние фильтров (search, role, …) остаётся на
 * странице и приходит сюда снимком через `params()`.
 *
 * Подходит страницам с дебаунс-поиском (Users, Roles). Страницам с мгновенными
 * фильтрами-чипами (ActivityLog) или клиентским фильтром + поллингом (Media)
 * этот composable не нужен — у них другая модель.
 *
 * @param {string} url — URL ресурса, например "/admin/users"
 * @param {() => Record<string, any>} params — снимок текущих фильтров,
 *        например () => ({ search: search.value, sort: props.currentSort, ... })
 * @param {{ debounce?: number }} [opts] — задержка дебаунса поиска (мс, по умолчанию 300)
 * @returns {{ reload: (extra?: object) => void, onSearch: () => void, onSort: (e: {key: string, dir: string}) => void }}
 */
export function useIndexFilters(url, params, { debounce = 300 } = {}) {
    let timer = null;

    function reload(extra = {}) {
        router.get(
            url,
            { ...params(), ...extra },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function onSearch() {
        clearTimeout(timer);
        timer = setTimeout(() => reload({ page: 1 }), debounce);
    }

    function onSort({ key, dir }) {
        reload({ sort: key, direction: dir, page: 1 });
    }

    // Не дать отложенному поиску дёрнуть reload() после ухода со страницы.
    onBeforeUnmount(() => clearTimeout(timer));

    return { reload, onSearch, onSort };
}
