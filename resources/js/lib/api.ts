import { router } from "@inertiajs/vue3";
import { useToast } from "nergous-ui-vue";
import { csrfHeaders } from "@/lib/csrf";

/** Raised by apiFetch when the session is gone; the redirect to the login page is already under way. */
export class SessionExpiredError extends Error {
    constructor() {
        super("Session expired");
        this.name = "SessionExpiredError";
    }
}

const REQUEST_KEY = "admin-last-request";
let redirecting = false;

/**
 * Remembers when the server last saw this browser, shared across tabs through
 * localStorage (every request extends the same session).
 */
export function touchSession(): void {
    try {
        localStorage.setItem(REQUEST_KEY, String(Date.now()));
    } catch {
        // Storage unavailable: the idle timer then only knows this tab.
    }
}

/** Time of the latest request to the server from any tab (ms since epoch). */
export function lastSessionTouch(): number {
    try {
        return Number(localStorage.getItem(REQUEST_KEY)) || 0;
    } catch {
        return 0;
    }
}

/** Sends the user to the login page once, with a toast explaining why. */
export function redirectToLogin(
    message = "Войдите снова, чтобы продолжить.",
): void {
    if (redirecting) return;
    redirecting = true;
    useToast().warning("Сессия истекла", message);
    router.visit("/admin/login", {
        onFinish: () => {
            redirecting = false;
        },
    });
}

/**
 * fetch() for the admin JSON endpoints: adds Accept, AJAX and CSRF headers and
 * handles a lost session (401/419) in one place.
 *
 * @throws SessionExpiredError when the server rejects the session.
 */
export async function apiFetch(
    input: string,
    init: RequestInit = {},
): Promise<Response> {
    const headers = new Headers(init.headers);
    if (!headers.has("Accept")) headers.set("Accept", "application/json");
    headers.set("X-Requested-With", "XMLHttpRequest");
    const method = (init.method ?? "GET").toUpperCase();
    if (method !== "GET" && method !== "HEAD") {
        for (const [name, value] of Object.entries(csrfHeaders())) {
            headers.set(name, value);
        }
    }

    const res = await fetch(input, {
        credentials: "same-origin",
        ...init,
        headers,
    });

    if (res.status === 401 || res.status === 419) {
        redirectToLogin();
        throw new SessionExpiredError();
    }

    touchSession();
    return res;
}
