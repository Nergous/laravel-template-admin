import { router } from "@inertiajs/vue3";
import { useToast } from "nergous-ui-vue";
import { csrfHeaders, refreshCsrfToken } from "@/lib/csrf";

/** Raised by apiFetch when the session is gone; the redirect to the login page is already under way. */
export class SessionExpiredError extends Error {
    constructor() {
        super("Session expired");
        this.name = "SessionExpiredError";
    }
}

const REQUEST_KEY = "admin-last-request";
const LOGIN_URL = "/admin/login";
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

/** True while the forced trip to the login page is under way; leave guards let it pass. */
export function isRedirectingToLogin(): boolean {
    return redirecting;
}

/**
 * Sends the user to the login page once, with a toast explaining why.
 *
 * The session is already gone, so the trip must not be stopped: unsaved-changes
 * guards skip it (see isRedirectingToLogin), and if some other "before" listener
 * still cancels the Inertia visit, a full page load takes over.
 */
export function redirectToLogin(
    message = "Войдите снова, чтобы продолжить.",
): void {
    if (redirecting) return;
    redirecting = true;
    useToast().warning("Сессия истекла", message);

    // Inertia fires its cancelable "before" event synchronously inside visit();
    // this listener runs after the others and sees whether any of them blocked it.
    let blocked = false;
    const watchBefore = (event: Event) => {
        blocked = event.defaultPrevented;
    };
    document.addEventListener("inertia:before", watchBefore);
    try {
        router.visit(LOGIN_URL, {
            onCancel: () => {
                redirecting = false;
            },
            onFinish: () => {
                redirecting = false;
            },
        });
    } finally {
        document.removeEventListener("inertia:before", watchBefore);
    }
    if (blocked) window.location.assign(LOGIN_URL);
}

function send(input: string, init: RequestInit): Promise<Response> {
    const headers = new Headers(init.headers);
    if (!headers.has("Accept")) headers.set("Accept", "application/json");
    headers.set("X-Requested-With", "XMLHttpRequest");
    const method = (init.method ?? "GET").toUpperCase();
    if (method !== "GET" && method !== "HEAD") {
        // Read on every attempt: a retry after a 419 must carry the new token.
        for (const [name, value] of Object.entries(csrfHeaders())) {
            headers.set(name, value);
        }
    }
    return fetch(input, {
        credentials: "same-origin",
        ...init,
        headers,
    });
}

/**
 * fetch() for the admin JSON endpoints: adds Accept, AJAX and CSRF headers and
 * handles a lost session in one place.
 *
 * A 419 (stale CSRF token, e.g. after signing in again in another tab) gets one
 * retry with a fresh token; if that fails too, the user sees an error toast and
 * the caller receives the 419 response. A 401 means the session is gone.
 *
 * @throws SessionExpiredError when the server rejects the session.
 */
export async function apiFetch(
    input: string,
    init: RequestInit = {},
): Promise<Response> {
    let res = await send(input, init);

    if (res.status === 419) {
        const refresh = await refreshCsrfToken();
        if (refresh === 401) {
            redirectToLogin();
            throw new SessionExpiredError();
        }
        res = await send(input, init);
        if (res.status === 419) {
            useToast().error(
                "Действие не выполнено",
                "Страница устарела. Обновите её и повторите действие.",
            );
        }
    }

    if (res.status === 401) {
        redirectToLogin();
        throw new SessionExpiredError();
    }

    touchSession();
    return res;
}
