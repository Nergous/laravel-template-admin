import { createFormat } from "nergous-ui-vue";

// Blade sets the document language used by application-level formatters.
const locale =
    (typeof document !== "undefined" && document.documentElement.lang) ||
    "ru-RU";

export const {
    toDate,
    formatDateTime,
    formatDateShort,
    formatRelative,
    formatNumber,
} = createFormat(locale);

/** Format a byte count with Russian units. */
export function formatBytes(bytes: number | string | null | undefined): string {
    const n = Number(bytes);
    if (!Number.isFinite(n) || n <= 0) return "0 Б";
    const units = ["Б", "КБ", "МБ", "ГБ", "ТБ"];
    const i = Math.min(
        units.length - 1,
        Math.floor(Math.log(n) / Math.log(1024)),
    );
    const v = n / 1024 ** i;
    const text = i === 0 ? String(Math.round(v)) : v.toFixed(1);
    return `${text} ${units[i]}`;
}

/** Choose a Russian singular, paucal, or plural form for a count. */
export function pluralize(
    n: number,
    one: string,
    few: string,
    many: string,
): string {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return one;
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return few;
    return many;
}
