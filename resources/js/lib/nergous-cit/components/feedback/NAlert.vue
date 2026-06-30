<script setup>
import { computed } from "vue";
import NIcon from "../primitives/NIcon.vue";

// NAlert — inline notification banner. Default slot is the message body.
// Props: title, icon (overrides the tone default). tone picks colors + default icon.
// tone: info | ok | warn | danger
// Live-region role is derived from tone (danger/warn = assertive "alert",
// info/ok = polite "status") so dynamically-inserted alerts get announced;
// pass `role` to override.
const props = defineProps({
    tone: {
        type: String,
        default: "info",
        validator: (v) => ["info", "ok", "warn", "danger"].includes(v),
    },
    title: { type: String, default: "" },
    icon: { type: String, default: "" },
    role: { type: String, default: "" },
});
// danger/warn share the warning triangle (matches NToaster); tone color = severity.
const DEFAULT_ICON = {
    info: "bell",
    ok: "check",
    warn: "alert-triangle",
    danger: "alert-triangle",
};
const liveRole = computed(
    () =>
        props.role ||
        (props.tone === "danger" || props.tone === "warn" ? "alert" : "status"),
);
</script>

<template>
    <div class="n-alert" :class="'n-alert--' + tone" :role="liveRole">
        <span class="n-alert__icon" aria-hidden="true"
            ><NIcon :name="icon || DEFAULT_ICON[tone]" :size="18"
        /></span>
        <div class="n-alert__body">
            <b v-if="title">{{ title }}</b> <slot />
        </div>
    </div>
</template>

<style scoped>
.n-alert {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 13px 15px;
    border-radius: var(--radius-md);
    font-size: 13px;
    line-height: 1.5;
    color: var(--text-2);
}
.n-alert__icon {
    flex: none;
    margin-top: 1px;
}
.n-alert b {
    font-weight: 700;
    color: var(--text);
}
/* Default/info tone: soft accent surface (spec §11, lines 372–375) */
.n-alert--info {
    background: var(--accent-soft);
}
.n-alert--info .n-alert__icon {
    color: var(--accent);
}
.n-alert--ok {
    background: var(--ok-bg);
}
.n-alert--ok .n-alert__icon,
.n-alert--ok b {
    color: var(--ok);
}
.n-alert--warn {
    background: var(--warn-bg);
}
.n-alert--warn .n-alert__icon,
.n-alert--warn b {
    color: var(--warn);
}
.n-alert--danger {
    background: var(--danger-bg);
}
.n-alert--danger .n-alert__icon,
.n-alert--danger b {
    color: var(--danger);
}
</style>
