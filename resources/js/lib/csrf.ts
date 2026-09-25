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
