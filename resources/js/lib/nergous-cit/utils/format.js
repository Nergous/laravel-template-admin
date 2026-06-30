// format.js — locale-agnostic formatting helpers for the nergous-cit DS.
// Pure Intl, with no hardcoded language or strings: the host app passes the active locale.
// Usage:
//   import { createFormat } from "@/lib/nergous-cit";
//   const { formatDateTime } = createFormat("ru-RU");
const EMPTY = "—";

// Parse a value into a valid Date, or null. Single parsing entry point (expects ISO 8601).
export function toDate(value) {
    if (!value) return null;
    const d = value instanceof Date ? value : new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
}

// Thresholds (in seconds) for choosing a relative-time unit, largest first.
const REL_UNITS = [
    ["year", 31536000],
    ["month", 2592000],
    ["day", 86400],
    ["hour", 3600],
    ["minute", 60],
];

// Build a set of formatters bound to one BCP-47 locale. Intl instances are cached in the closure.
export function createFormat(locale) {
    const dtf = new Intl.DateTimeFormat(locale, {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
    const dshort = new Intl.DateTimeFormat(locale, {
        day: "numeric",
        month: "short",
        year: "numeric",
    });
    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: "auto" });
    const nf = new Intl.NumberFormat(locale);

    return {
        toDate,

        // Date + time, e.g. ru → "12.03.2025, 10:00".
        formatDateTime(value) {
            const d = toDate(value);
            return d ? dtf.format(d) : EMPTY;
        },

        // Short date, e.g. ru → "12 мар. 2025 г.", en → "Mar 12, 2025".
        formatDateShort(value) {
            const d = toDate(value);
            return d ? dshort.format(d) : EMPTY;
        },

        // Relative time, e.g. ru → "2 мин назад", "вчера", "сейчас".
        formatRelative(value) {
            const d = toDate(value);
            if (!d) return EMPTY;
            const sec = Math.round((d.getTime() - Date.now()) / 1000);
            const abs = Math.abs(sec);
            for (const [unit, secs] of REL_UNITS) {
                if (abs >= secs)
                    return rtf.format(Math.round(sec / secs), unit);
            }
            return rtf.format(0, "second"); // ru: "сейчас", en: "now"
        },

        // Grouped large numbers, e.g. ru → 1284 → "1 284".
        formatNumber(value) {
            const n = typeof value === "number" ? value : Number(value);
            return Number.isFinite(n) ? nf.format(n) : String(value ?? "");
        },
    };
}
