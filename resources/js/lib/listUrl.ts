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

const CURRENT_KEY = "admin-nav:current";
const PREVIOUS_KEY = "admin-nav:previous";
const pathOf = (url: string) => url.split(/[?#]/)[0];

/**
 * Tracks the page the user came from. Reloads of the same page (validation
 * errors, filter changes) keep the earlier origin. Called on every Inertia
 * navigation.
 */
export function rememberPreviousPage(url: string): void {
    try {
        const current = sessionStorage.getItem(CURRENT_KEY);
        if (current !== null && pathOf(current) !== pathOf(url)) {
            sessionStorage.setItem(PREVIOUS_KEY, current);
        }
        sessionStorage.setItem(CURRENT_KEY, url);
    } catch {
        // Storage unavailable: cameFrom() answers false.
    }
}

/**
 * Whether the user came to the current page from the given path (query
 * string ignored). Works both before and after rememberPreviousPage() has
 * recorded the current page: Inertia may render the new page first.
 */
export function cameFrom(path: string): boolean {
    try {
        const here = window.location.pathname;
        const current = sessionStorage.getItem(CURRENT_KEY);
        const origin =
            current !== null && pathOf(current) !== here
                ? current
                : sessionStorage.getItem(PREVIOUS_KEY);
        return origin !== null && pathOf(origin) === path;
    } catch {
        return false;
    }
}
