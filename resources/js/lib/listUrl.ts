/**
 * Remembers the last URL of each list page (with its filters, sort and page)
 * for the tab, so "back to the list" links on detail pages return to the same view.
 */
const LIST_PATHS = ["/admin/users", "/admin/roles"];
const PREFIX = "admin-list:";

/** Stores the URL when it is one of the tracked list pages. Called on every Inertia navigation. */
export function rememberListUrl(url: string): void {
    const path = url.split("?")[0];
    if (!LIST_PATHS.includes(path)) return;
    try {
        sessionStorage.setItem(PREFIX + path, url);
    } catch {
        // Storage unavailable: links fall back to the bare list URL.
    }
}

/** The remembered URL of a list page, or the page itself without filters. */
export function listUrl(path: string): string {
    try {
        const stored = sessionStorage.getItem(PREFIX + path);
        if (stored && stored.split("?")[0] === path) return stored;
    } catch {
        // Fall through to the plain path.
    }
    return path;
}
