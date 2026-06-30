// Deterministic "badge" color from a string (role/tag name): a stable hash of
// the name → index in the palette. A single source of color across all pages, so
// one role is colored the same everywhere. An application-level presentational
// utility (not DS — the design system stays locale/domain-agnostic).
const PALETTE = [
    "#6f76f5", // indigo (DS "Owner")
    "#2f6bff", // blue
    "#27a567", // green
    "#e0a23b", // amber (DS "Editor")
    "#8a90a2", // gray (DS "Observer")
    "#d6516b", // rose
    "#3aa6b9", // teal
    "#9b5de5", // violet
];

export function swatchColor(name) {
    const str = String(name ?? "");
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = (hash * 31 + str.charCodeAt(i)) >>> 0;
    }
    return PALETTE[hash % PALETTE.length];
}
