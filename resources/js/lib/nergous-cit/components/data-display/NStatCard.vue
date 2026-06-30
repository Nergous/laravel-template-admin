<script setup>
import { computed } from "vue";
import NIcon from "../primitives/NIcon.vue";

// NStatCard — KPI/metric tile. Props: label, value, sub (caption line), icon.
// Optional extras: delta (change text), trend (up | down) colors the delta,
// spark (array of numbers) renders a sparkline. The dashboard uses only
// label/value/sub/icon; delta/trend/spark are kept for other surfaces.
const props = defineProps({
    label: { type: String, default: "" },
    value: { type: [String, Number], default: "" },
    sub: { type: String, default: "" },
    delta: { type: String, default: "" },
    trend: {
        type: String,
        default: "up",
        validator: (v) => ["up", "down"].includes(v),
    }, // up | down
    spark: { type: Array, default: () => [] },
    icon: { type: String, default: "" },
});

const spark = computed(() => {
    const d = props.spark;
    if (!d || d.length < 2) return null;
    const w = 120,
        h = 36,
        p = 3;
    const max = Math.max(...d),
        min = Math.min(...d);
    const x = (i) => p + (i / (d.length - 1)) * (w - 2 * p);
    const y = (v) => h - p - ((v - min) / (max - min || 1)) * (h - 2 * p);
    const line =
        "M " +
        d.map((v, i) => x(i).toFixed(1) + " " + y(v).toFixed(1)).join(" L ");
    return line;
});
</script>

<template>
    <div class="n-stat">
        <div class="n-stat__top">
            <span v-if="icon" class="n-stat__ic"
                ><NIcon :name="icon" :size="16"
            /></span>
            <span class="n-stat__label">{{ label }}</span>
            <span v-if="delta" class="n-stat__delta" :class="trend">
                <NIcon
                    :name="trend === 'up' ? 'arrow-up' : 'arrow-down'"
                    :size="12"
                />{{ delta }}
            </span>
        </div>
        <div class="n-stat__value">{{ value }}</div>
        <div v-if="sub" class="n-stat__sub">{{ sub }}</div>
        <svg
            v-if="spark"
            viewBox="0 0 120 36"
            preserveAspectRatio="none"
            class="n-stat__spark"
            aria-hidden="true"
        >
            <path
                :d="spark"
                fill="none"
                stroke="var(--accent)"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </div>
</template>

<style scoped>
.n-stat {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: var(--kpi-pad, 20px);
    transition: padding 0.18s ease;
}
.n-stat__top {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}
.n-stat__ic {
    width: var(--kpi-icon, 30px);
    height: var(--kpi-icon, 30px);
    border-radius: 8px;
    background: var(--accent-soft);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
}
.n-stat__label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-2);
}
.n-stat__delta {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    height: 21px;
    padding: 0 7px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 700;
}
.n-stat__delta.up {
    color: var(--ok);
    background: var(--ok-bg);
}
.n-stat__delta.down {
    color: var(--danger);
    background: var(--danger-bg);
}
.n-stat__value {
    font-size: var(--kpi-value-fs, 27px);
    font-weight: 800;
    letter-spacing: -0.03em;
    line-height: 1;
    color: var(--text);
    transition: font-size 0.18s ease;
}
.n-stat__sub {
    font-size: 12px;
    color: var(--text-3);
    margin-top: 5px;
}
.n-stat__spark {
    width: 100%;
    height: 34px;
    margin-top: 12px;
    display: block;
}
</style>
