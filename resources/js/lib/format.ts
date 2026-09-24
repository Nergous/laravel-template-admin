import { createFormat, toDate } from "nergous-ui-vue";

// Blade sets the document language used by application-level formatters.
const locale =
    (typeof document !== "undefined" && document.documentElement.lang) ||
    "ru-RU";

const EMPTY = "—";

// Relative time and numbers do not depend on the time zone.
export const { formatRelative, formatNumber } = createFormat(locale);
export { toDate };

// Display time zone from the settings (shared prop `timezone`); dates are stored in UTC.
let timeZone: string | undefined;
let dateTime: Intl.DateTimeFormat;
let dateShort: Intl.DateTimeFormat;
let dateOnly: Intl.DateTimeFormat;

function build() {
    const base = { timeZone } as const;
    dateTime = new Intl.DateTimeFormat(locale, {
        ...base,
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
    dateShort = new Intl.DateTimeFormat(locale, {
        ...base,
        day: "numeric",
        month: "short",
        year: "numeric",
    });
    dateOnly = new Intl.DateTimeFormat("sv-SE", {
        ...base,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });
}
build();

/** Switch the zone used by the date formatters; invalid zones fall back to the browser's. */
export function setDisplayTimeZone(zone: string | null | undefined): void {
    const next = zone || undefined;
    if (next === timeZone) return;
    try {
        new Intl.DateTimeFormat(locale, { timeZone: next });
        timeZone = next;
    } catch {
        timeZone = undefined;
    }
    build();
}

/** Day, month, year, hours, and minutes in the display time zone. */
export function formatDateTime(value: Parameters<typeof toDate>[0]): string {
    const d = toDate(value);
    return d ? dateTime.format(d) : EMPTY;
}

/** Day, short month, and year in the display time zone. */
export function formatDateShort(value: Parameters<typeof toDate>[0]): string {
    const d = toDate(value);
    return d ? dateShort.format(d) : EMPTY;
}

/** Today as YYYY-MM-DD in the display time zone (for date inputs). */
export function todayIso(): string {
    return dateOnly.format(new Date());
}

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
