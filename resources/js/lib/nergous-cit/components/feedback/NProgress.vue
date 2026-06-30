<script setup>
import { computed, useId } from "vue";

// NProgress — horizontal determinate progress bar. Props: label, showValue
// (print the %), tone (accent | ok | warn | danger). Exposes the WAI-ARIA
// progressbar role with aria-valuenow so screen readers track progress.
const props = defineProps({
    value: { type: Number, default: 0 }, // 0..100
    label: { type: String, default: "" },
    showValue: { type: Boolean, default: false },
    tone: {
        type: String,
        default: "accent",
        validator: (v) => ["accent", "ok", "warn", "danger"].includes(v),
    }, // accent | ok | warn | danger
});
const pct = computed(() => Math.max(0, Math.min(100, props.value)));
const color = computed(
    () =>
        ({
            accent: "var(--accent)",
            ok: "var(--ok)",
            warn: "var(--warn)",
            danger: "var(--danger)",
        })[props.tone] || "var(--accent)",
);
const labelId = useId();
</script>

<template>
    <div class="n-progress">
        <div v-if="label || showValue" class="n-progress__head">
            <span :id="labelId" class="n-progress__label">{{ label }}</span>
            <span v-if="showValue" class="n-progress__value">{{ pct }}%</span>
        </div>
        <div
            class="n-progress__track"
            role="progressbar"
            :aria-valuenow="pct"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-labelledby="label ? labelId : undefined"
        >
            <div
                class="n-progress__bar"
                :style="{ width: pct + '%', background: color }"
            />
        </div>
    </div>
</template>

<style scoped>
.n-progress {
    width: 100%;
}
.n-progress__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-2);
    margin-bottom: 7px;
}
.n-progress__value {
    font-family: var(--font-mono);
    color: var(--text-3);
}
.n-progress__track {
    height: 7px;
    border-radius: 999px;
    background: var(--surface-3);
    overflow: hidden;
}
.n-progress__bar {
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease;
}
</style>
