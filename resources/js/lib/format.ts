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
let wallClock = wallClockFormat();

function isoDate() {
    return new Intl.DateTimeFormat("sv-SE", {
        timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });
}

// Numeric wall-clock parts in the display zone, for the datetime-local helpers.
function wallClockFormat() {
    return new Intl.DateTimeFormat("en-US", {
        timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hourCycle: "h23",
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
    wallClock = wallClockFormat();
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

/** Wall-clock parts of a moment in the display time zone. */
function wallParts(ms: number) {
    const parts: Record<string, number> = {};
    for (const part of wallClock.formatToParts(new Date(ms))) {
        if (part.type !== "literal") parts[part.type] = Number(part.value);
    }
    return parts;
}

/** Offset of the display time zone at a moment, in ms (UTC+3 → 3 h). */
function zoneOffset(ms: number): number {
    const p = wallParts(ms);
    const asUtc = Date.UTC(
        p.year,
        p.month - 1,
        p.day,
        p.hour % 24,
        p.minute,
        p.second,
    );
    return asUtc - Math.floor(ms / 1000) * 1000;
}

/**
 * UTC ISO string → value of an <input type="datetime-local"> in the display
 * time zone of the site (not the browser's), so editors in other zones see
 * the same time as the site and the lists.
 */
export function toZonedInput(iso: string | null | undefined): string {
    const d = toDate(iso ?? null);
    if (!d) return "";
    const p = wallParts(d.getTime());
    const pad = (n: number) => String(n).padStart(2, "0");
    return (
        p.year +
        "-" +
        pad(p.month) +
        "-" +
        pad(p.day) +
        "T" +
        pad(p.hour % 24) +
        ":" +
        pad(p.minute)
    );
}

/** Value of a datetime-local input read in the display time zone → UTC ISO string. */
export function fromZonedInput(
    value: string | null | undefined,
): string | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value ?? "");
    if (!m) return null;
    const [, y, mo, d, h, mi] = m.map(Number);
    const wall = Date.UTC(y, mo - 1, d, h, mi);
    // Two passes settle the offset around daylight-saving switches.
    let ms = wall - zoneOffset(wall);
    ms = wall - zoneOffset(ms);
    return new Date(ms).toISOString();
}
