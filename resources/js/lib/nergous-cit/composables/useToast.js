// useToast — global toast store. Render <NToaster /> once near the app root, then
// push messages from anywhere via useToast() (success / error / warning / info).
import { reactive } from "vue";

// Single shared list of active toasts (module-level singleton).
const state = reactive({ toasts: [] });
let uid = 0;

// Arm/disarm a toast's auto-dismiss timer. `remaining` tracks the time left so
// pauseAll/resumeAll (pointer/focus inside the toaster) can stop and resume it.
function arm(t) {
    t.startedAt = Date.now();
    t.timer = setTimeout(() => dismiss(t.id), t.remaining);
}
function disarm(t) {
    if (t.timer) {
        clearTimeout(t.timer);
        t.timer = null;
    }
}

// Add a toast. Accepts a string (title only) or an options object
// ({ tone, title, msg, duration }). Returns the new toast's id.
function push(opts) {
    const o = typeof opts === "string" ? { title: opts } : opts || {};
    const tone = o.tone || "ok";
    const t = {
        id: ++uid,
        tone: "ok",
        title: "",
        msg: "",
        // danger/warn linger longer — errors need more reading time before they
        // auto-dismiss (WCAG 2.2.1); ok/info stay transient. Explicit `duration` wins.
        duration: tone === "danger" ? 7000 : tone === "warn" ? 5000 : 3600,
        ...o,
        timer: null,
        startedAt: 0,
    };
    t.remaining = t.duration;
    state.toasts.push(t);
    // Schedule auto-dismiss; keep the timer on the toast so manual dismiss can cancel it.
    if (t.duration > 0) arm(t);
    return t.id;
}

// Remove a toast by id, cancelling its pending auto-dismiss timer if still armed.
function dismiss(id) {
    const i = state.toasts.findIndex((t) => t.id === id);
    if (i > -1) {
        disarm(state.toasts[i]);
        state.toasts.splice(i, 1);
    }
}

// Pause every armed countdown and bank how much time each toast has left;
// resumeAll re-arms from there. Used while the pointer/focus is in the toaster
// so messages don't vanish out from under someone reading them (WCAG 2.2.1).
function pauseAll() {
    for (const t of state.toasts) {
        if (t.timer) {
            t.remaining -= Date.now() - t.startedAt;
            disarm(t);
        }
    }
}
function resumeAll() {
    for (const t of state.toasts) {
        if (t.duration > 0 && !t.timer && t.remaining > 0) arm(t);
    }
}

// Public API: the reactive list plus push/dismiss, pause/resume, and tone shortcuts.
export function useToast() {
    return {
        toasts: state.toasts,
        push,
        dismiss,
        pauseAll,
        resumeAll,
        success: (title, msg) => push({ tone: "ok", title, msg }),
        error: (title, msg) => push({ tone: "danger", title, msg }),
        warning: (title, msg) => push({ tone: "warn", title, msg }),
        info: (title, msg) => push({ tone: "info", title, msg }),
    };
}
