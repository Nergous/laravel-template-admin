// Application formatting — binds the DS's locale-agnostic factory to the project's locale.
// The locale is taken from <html lang> (set by Blade) — a valid BCP-47 for Intl.
// Call sites import the named functions from here as before.
import { createFormat } from "@/lib/nergous-cit";

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

// File size (bytes) → human-readable, RU units. Application-level presentational
// formatting (outside the DS's locale-agnostic factory).
export function formatBytes(bytes) {
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

// RU declension by number: pluralize(n, "файл", "файла", "файлов").
export function pluralize(n, one, few, many) {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return one;
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return few;
    return many;
}
