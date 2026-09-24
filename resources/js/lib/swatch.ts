const PALETTE = [
    "#6f76f5",
    "#2f6bff",
    "#27a567",
    "#e0a23b",
    "#8a90a2",
    "#d6516b",
    "#3aa6b9",
    "#9b5de5",
];

/** Pick a stable badge color for a role or tag name. */
export function swatchColor(name: string | null | undefined): string {
    const str = String(name ?? "");
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = (hash * 31 + str.charCodeAt(i)) >>> 0;
    }
    return PALETTE[hash % PALETTE.length];
}
