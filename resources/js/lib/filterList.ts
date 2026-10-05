// Multi-value list filters: several choices of one filter travel as one
// comma-separated query parameter (?type=article,interview), like the server
// reads them (App\Support\FilterValues). Choices of one filter combine with OR,
// different filters with AND.

export type FilterOption = { value: string; label: string };

/** A chip of NFilterChips: one per chosen value. */
export type FilterChip = { key: string; label: string; value: string };

/** Values of a filter from the server props: "a,b", ["a", "b"] or nothing. */
export function parseList(raw: unknown): string[] {
    const items = Array.isArray(raw)
        ? raw
        : typeof raw === "string" || typeof raw === "number"
          ? String(raw).split(",")
          : [];
    const values: string[] = [];
    for (const item of items) {
        if (typeof item !== "string" && typeof item !== "number") continue;
        const value = String(item).trim();
        if (value && !values.includes(value)) values.push(value);
    }
    return values;
}

/** The query value of a filter; undefined drops an empty filter from the URL. */
export function joinList(values: readonly string[]): string | undefined {
    return values.length ? values.join(",") : undefined;
}

export function labelOf(
    options: readonly FilterOption[],
    value: string,
): string {
    return options.find((option) => option.value === value)?.label ?? value;
}

/**
 * Text of a closed multi-select: the placeholder, the chosen label, or the
 * first chosen label with the count of the others ("Статья +2").
 */
export function listSummary(
    values: readonly string[],
    options: readonly FilterOption[],
    placeholder: string,
): string {
    if (!values.length) return placeholder;
    const first = labelOf(options, values[0]);
    return values.length === 1 ? first : first + " +" + (values.length - 1);
}

const SEPARATOR = "\u0000";

/** One removable chip per chosen value; the key names the filter and the value. */
export function listChips(
    name: string,
    label: string,
    values: readonly string[],
    options: readonly FilterOption[],
): FilterChip[] {
    return values.map((value) => ({
        key: name + SEPARATOR + value,
        label,
        value: labelOf(options, value),
    }));
}

/** The filter name and value of a chip key from listChips; value null for other chips. */
export function chipTarget(key: string): {
    name: string;
    value: string | null;
} {
    const at = key.indexOf(SEPARATOR);
    return at < 0
        ? { name: key, value: null }
        : { name: key.slice(0, at), value: key.slice(at + 1) };
}

/** The values without one value. */
export function without(values: readonly string[], value: string): string[] {
    return values.filter((item) => item !== value);
}
