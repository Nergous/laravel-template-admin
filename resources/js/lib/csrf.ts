/**
 * CSRF headers for requests made outside Inertia (fetch/XHR).
 *
 * Reads the XSRF-TOKEN cookie on every call, as Inertia does. The csrf-token
 * meta tag is rendered once per full page load and goes stale when the token
 * rotates during the SPA session (sign out and back in), which turned uploads
 * into 419 errors. The meta tag stays as a fallback for cookie-less setups.
 */
export function csrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (match) return { "X-XSRF-TOKEN": decodeURIComponent(match[1]) };

    const meta = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    return meta ? { "X-CSRF-TOKEN": meta } : {};
}

// Any GET through the web middleware re-issues the XSRF-TOKEN cookie. The search
// endpoint is cheap (an empty query returns no rows) and needs a signed-in user,
// so its status also tells whether the session is still alive.
const REFRESH_URL = "/admin/search";

/**
 * Fetches a fresh XSRF-TOKEN cookie after the server rejected a stale token (419).
 *
 * @returns HTTP status of the refresh request (401 means the session is gone), 0 when offline.
 */
export async function refreshCsrfToken(): Promise<number> {
    try {
        const res = await fetch(REFRESH_URL, {
            credentials: "same-origin",
            cache: "no-store",
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        });
        return res.status;
    } catch {
        return 0;
    }
}
