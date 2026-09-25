import { onBeforeUnmount, onMounted, ref, type Ref } from "vue";
import { router } from "@inertiajs/vue3";
import {
    apiFetch,
    lastSessionTouch,
    redirectToLogin,
    touchSession,
} from "@/lib/api";

/** How long without input still counts as "working" (background polling stops after it). */
export const ACTIVE_WINDOW_MS = 5 * 60_000;
const WARN_BEFORE_MS = 60_000;
const CHECK_EVERY_MS = 5_000;
const ACTIVITY_EVENTS = ["pointerdown", "keydown", "wheel", "touchstart"] as const;

/**
 * Tracks user activity against the server session lifetime.
 *
 * While the user works, a quiet ping keeps the session alive even if they stay
 * on one page (a long form). When they are idle, background polling stops (so
 * it no longer extends the session), a warning appears a minute before the
 * session ends, and at the end the user is sent to the login page.
 *
 * @param lifetimeMinutes Session lifetime; null disables the timer ("remember me").
 */
export function useSessionTimeout(lifetimeMinutes: () => number | null): {
    warning: Ref<boolean>;
    secondsLeft: Ref<number>;
    isActive: () => boolean;
    stayActive: () => Promise<void>;
} {
    const warning = ref(false);
    const secondsLeft = ref(0);
    let lastActivity = Date.now();
    let pinging = false;
    let timer: ReturnType<typeof setInterval> | null = null;
    let offFinish: (() => void) | null = null;

    const isActive = () => Date.now() - lastActivity < ACTIVE_WINDOW_MS;

    async function ping() {
        if (pinging) return;
        pinging = true;
        try {
            await apiFetch("/admin/session/ping", { method: "POST" });
        } catch {
            // Expired: apiFetch has already redirected. Offline: the next check retries.
        } finally {
            pinging = false;
        }
    }

    function onActivity() {
        lastActivity = Date.now();
        const lifetime = lifetimeMinutes();
        if (warning.value || !lifetime) return;
        // Refresh the session in the background once half of it has passed.
        if (Date.now() - lastSessionTouch() > Math.min(ACTIVE_WINDOW_MS, (lifetime * 60_000) / 2)) {
            ping();
        }
    }

    function check() {
        const lifetime = lifetimeMinutes();
        if (!lifetime) {
            warning.value = false;
            return;
        }
        const left = lifetime * 60_000 - (Date.now() - lastSessionTouch());
        if (left <= 0) {
            warning.value = false;
            redirectToLogin("Сессия завершилась из-за бездействия.");
            return;
        }
        warning.value = left <= WARN_BEFORE_MS;
        secondsLeft.value = Math.ceil(left / 1000);
    }

    async function stayActive() {
        lastActivity = Date.now();
        await ping();
        check();
    }

    onMounted(() => {
        touchSession();
        for (const name of ACTIVITY_EVENTS) {
            window.addEventListener(name, onActivity, { passive: true });
        }
        offFinish = router.on("finish", () => touchSession());
        timer = setInterval(check, CHECK_EVERY_MS);
    });

    onBeforeUnmount(() => {
        for (const name of ACTIVITY_EVENTS) {
            window.removeEventListener(name, onActivity);
        }
        offFinish?.();
        if (timer) clearInterval(timer);
    });

    return { warning, secondsLeft, isActive, stayActive };
}
