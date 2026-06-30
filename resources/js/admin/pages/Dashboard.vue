<script setup>
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import { NCard, NStatCard, NActivityRow, NEmptyState } from "@/lib/nergous-cit";
import { formatNumber } from "@/lib/format.js";
import { swatchColor } from "@/lib/swatch.js";

const props = defineProps({
    stats: { type: Object, required: true }, // { users, roles, permissions, media } each {value, sub}
    roleDistribution: { type: Array, default: () => [] }, // [{ name, count }]
    recentActivity: { type: Array, default: () => [] },
});

const cards = [
    { key: "users", label: "Пользователи", icon: "users" },
    { key: "roles", label: "Роли", icon: "shield" },
    { key: "permissions", label: "Разрешения", icon: "lock" },
    { key: "media", label: "Медиафайлы", icon: "asset" },
];

// Render only the cards for which the server sent a metric — so the
// template doesn't break if the stats set is trimmed (e.g. the media library is removed).
const visibleCards = computed(() => cards.filter((c) => props.stats[c.key]));

// Domain/locale mapping lives in the page; NActivityRow stays presentational.
const ACT = {
    created: { verb: "создано", tone: "ok", icon: "plus" },
    updated: { verb: "изменено", tone: "info", icon: "edit" },
    deleted: { verb: "удалено", tone: "danger", icon: "trash" },
    restored: { verb: "восстановлено", tone: "ok", icon: "check" },
    duplicated: { verb: "дублировано", tone: "warn", icon: "copy" },
    force_deleted: { verb: "удалено навсегда", tone: "danger", icon: "trash" },
};

// Maps action → presentation (verb/tone/icon); unknown falls back to a neutral default.
const actOf = (a) => ACT[a.action] || {};
const actMeta = (a) => (a.changes_count ? `${a.changes_count} изм.` : "");

const bars = computed(() => {
    const total = props.roleDistribution.reduce((s, r) => s + r.count, 0) || 1;
    // Same name-hash palette as Roles/Users/Permissions so a role keeps one color everywhere.
    return props.roleDistribution.map((r) => ({
        name: r.name,
        count: r.count,
        pct: Math.round((r.count / total) * 100),
        color: swatchColor(r.name),
    }));
});
</script>

<template>
    <AdminLayout title="Главная" subtitle="Обзор рабочего пространства">
        <div class="kpi-grid">
            <NStatCard
                v-for="c in visibleCards"
                :key="c.key"
                :label="c.label"
                :icon="c.icon"
                :value="formatNumber(stats[c.key].value)"
                :sub="stats[c.key].sub"
            />
        </div>

        <div class="grid-2">
            <NCard padding="0" class="activity-card">
                <div class="card-head card-head--inset">
                    <h2 class="card-title">Последние действия</h2>
                    <Link href="/admin/activity-log" class="dash-link"
                        >Весь журнал →</Link
                    >
                </div>
                <template v-if="recentActivity.length">
                    <NActivityRow
                        v-for="a in recentActivity"
                        :key="a.id"
                        :tone="actOf(a).tone || 'info'"
                        :icon="actOf(a).icon || 'edit'"
                        :actor="a.user || 'Система'"
                        :verb="actOf(a).verb || a.action"
                        :object="a.subject_label || ''"
                        :tag="a.subject_type || ''"
                        :time="a.created_human || ''"
                        :meta="actMeta(a)"
                    />
                </template>
                <NEmptyState
                    v-else
                    icon="activity"
                    title="Активности пока нет"
                    description="Действия пользователей появятся здесь."
                />
            </NCard>

            <NCard padding="20px">
                <div class="card-head">
                    <h2 class="card-title">Распределение ролей</h2>
                </div>
                <div v-for="b in bars" :key="b.name" class="bar-row">
                    <div class="bar-top">
                        <span
                            ><i :style="{ background: b.color }" />{{
                                b.name
                            }}</span
                        >
                        <span class="bar-count">{{ b.count }}</span>
                    </div>
                    <div class="bar">
                        <div
                            :style="{ width: b.pct + '%', background: b.color }"
                        />
                    </div>
                </div>
            </NCard>
        </div>
    </AdminLayout>
</template>

<style scoped>
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--kpi-gap, 16px);
}
.grid-2 {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 16px;
    margin-top: 16px;
}
@media (max-width: 920px) {
    .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .grid-2 {
        grid-template-columns: 1fr;
    }
}
.card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.card-title {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.01em;
}
/* Activity card renders its row list edge-to-edge: NCard padding is 0, an
   inset header carries the spacing, and the NActivityRow dividers reach the
   card edges (matching the DS activity feed). */
.activity-card {
    overflow: hidden;
}
.card-head--inset {
    padding: 20px 16px 14px;
    margin-bottom: 0;
}
.dash-link {
    color: var(--accent);
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
}
.dash-link:hover {
    text-decoration: underline;
}
.bar-row {
    margin-bottom: 14px;
}
.bar-row:last-child {
    margin-bottom: 0;
}
.bar-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 600;
}
.bar-top span:first-child {
    display: flex;
    align-items: center;
    gap: 8px;
}
.bar-top i {
    width: 9px;
    height: 9px;
    border-radius: 3px;
}
.bar-count {
    font-family: var(--font-mono);
}
.bar {
    height: 7px;
    border-radius: 999px;
    background: var(--surface-3);
    overflow: hidden;
}
.bar > div {
    height: 100%;
    border-radius: 999px;
}
</style>
