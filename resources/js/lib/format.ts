import { createFormat, toDate, type PluralForms } from "nergous-ui-vue";

// Blade sets the document language used by application-level formatters.
const locale =
    (typeof document !== "undefined" && document.documentElement.lang) ||
    "ru-RU";

type DateValue = Parameters<typeof toDate>[0];

// Display time zone from the settings (shared prop `timezone`); dates are
// stored in UTC. createFormat validates the zone and falls back to the host's.
let timeZone: string | undefined;
let format = createFormat(locale);
let dateOnly = isoDate();

function isoDate() {
    return new Intl.DateTimeFormat("sv-SE", {
        timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });
}

export { toDate };

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
    format = createFormat(locale, { timeZone });
    dateOnly = isoDate();
}

/** Day, month, year, hours, and minutes in the display time zone. */
export function formatDateTime(value: DateValue): string {
    return format.formatDateTime(value);
}

/** Day, short month, and year in the display time zone. */
export function formatDateShort(value: DateValue): string {
    return format.formatDateShort(value);
}

/** Time relative to now ("5 минут назад"). */
export function formatRelative(value: DateValue): string {
    return format.formatRelative(value);
}

/** A number with locale grouping; an em dash for empty input. */
export function formatNumber(
    value: string | number | null | undefined,
): string {
    return format.formatNumber(value);
}

/** A byte count with 1024-based locale units ("1,5 МБ"); an em dash for empty input. */
export function formatBytes(value: string | number | null | undefined): string {
    return format.formatBytes(value);
}

/** Today as YYYY-MM-DD in the display time zone (for date inputs). */
export function todayIso(): string {
    return dateOnly.format(new Date());
}

/** Text for a count by the locale's plural rules; "#" inserts the count. */
export function plural(count: number, forms: PluralForms): string {
    return format.plural(count, forms);
}

/** Choose a Russian singular, paucal, or plural form for a count. */
export function pluralize(
    n: number,
    one: string,
    few: string,
    many: string,
): string {
    return format.plural(n, { one, few, many, other: few });
}
