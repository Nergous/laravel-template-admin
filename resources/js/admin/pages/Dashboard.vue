<script setup lang="ts">
import { computed } from "vue";
import type { PropType } from "vue";
import { Link } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NCard,
    NStatCard,
    NActivityRow,
    NEmptyState,
    NBadge,
} from "nergous-ui-vue";
import { can } from "@/lib/can";
import {
    formatBytes,
    formatDateTime,
    formatNumber,
    formatRelative,
} from "@/lib/format";
import { swatchColor } from "@/lib/swatch";
import { activityVisual } from "@/admin/activityVisuals";

type StatCard = { value: number; sub: string; bytes?: number; spark?: number[] };

type SystemHealth = {
    queue: {
        pending: number | null;
        oldest_pending_at: string | null;
        failed: number;
    } | null;
    backup: {
        latest: { name: string; created_at: string } | null;
        stale: boolean;
    } | null;
};

const props = defineProps({
    stats: {
        type: Object as PropType<Record<string, StatCard>>,
        required: true,
    },
    roleDistribution: {
        type: Array as PropType<{ name: string; count: number }[]>,
        default: () => [],
    },
    recentActivity: {
        type: Array as PropType<
            {
                id: number;
                user: string | null;
                action: string;
                action_label: string;
                subject_label: string;
                subject_type: string;
                changes_count: number;
                created_human: string;
            }[]
        >,
        default: () => [],
    },
    system: {
        type: Object as PropType<SystemHealth>,
        default: () => ({ queue: null, backup: null }),
    },
});

const cards = [
    { key: "users", label: "Пользователи", icon: "users" },
    { key: "roles", label: "Роли", icon: "shield" },
    { key: "permissions", label: "Разрешения", icon: "lock" },
    { key: "media", label: "Медиафайлы", icon: "asset" },
    { key: "logins", label: "Входы за 24 ч", icon: "activity" },
];

// Render only the cards for which the server sent a metric — so the
// template doesn't break if the stats set is trimmed (e.g. the media library is removed).
const visibleCards = computed(() => cards.filter((c) => props.stats[c.key]));

const cardSub = (s: StatCard) =>
    s.bytes !== undefined ? `${formatBytes(s.bytes)} · ${s.sub}` : s.sub;

// A pending job older than 15 minutes means the worker is down (same threshold as /up).
const QUEUE_STALE_MS = 15 * 60 * 1000;
const queueStuck = computed(() => {
    const at = props.system.queue?.oldest_pending_at;
    return !!at && Date.now() - new Date(at).getTime() > QUEUE_STALE_MS;
});
const showSystem = computed(
    () => !!props.system.queue || !!props.system.backup,
);

// Tone and icon per action; the verb comes from the server (lang/ru/activity.php).
const actOf = (a: { action: string }) => activityVisual(a.action);
const actMeta = (a: { changes_count: number }) =>
    a.changes_count ? `${a.changes_count} изм.` : "";

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
                :sub="cardSub(stats[c.key])"
                :spark="stats[c.key].spark"
            />
        </div>

        <NCard v-if="showSystem" padding="20px" class="system-card">
            <div class="card-head">
                <h2 class="card-title">Состояние системы</h2>
            </div>
            <div class="system-grid">
                <Link
                    v-if="system.queue"
                    href="/admin/queue"
                    class="system-item"
                >
                    <span class="system-label">Очередь задач</span>
                    <span class="system-value">
                        <template v-if="system.queue.pending === null"
                            >не database-драйвер</template
                        >
                        <template v-else
                            >{{ formatNumber(system.queue.pending) }} в
                            ожидании</template
                        >
                        <NBadge v-if="queueStuck" tone="warn" size="sm"
                            >воркер не отвечает</NBadge
                        >
                    </span>
                    <span
                        v-if="system.queue.oldest_pending_at"
                        class="system-sub"
                        >Самая старая:
                        {{ formatRelative(system.queue.oldest_pending_at) }}</span
                    >
                </Link>
                <Link
                    v-if="system.queue"
                    href="/admin/queue"
                    class="system-item"
                >
                    <span class="system-label">Упавшие задачи</span>
                    <span class="system-value">
                        {{ formatNumber(system.queue.failed) }}
                        <NBadge
                            v-if="system.queue.failed > 0"
                            tone="danger"
                            size="sm"
                            >требуют внимания</NBadge
                        >
                    </span>
                </Link>
                <Link
                    v-if="system.backup"
                    href="/admin/backups"
                    class="system-item"
                >
                    <span class="system-label">Последний бэкап</span>
                    <span class="system-value">
                        <template v-if="system.backup.latest">{{
                            formatRelative(system.backup.latest.created_at)
                        }}</template>
                        <template v-else>ещё не создавался</template>
                        <NBadge v-if="system.backup.stale" tone="warn" size="sm"
                            >устарел</NBadge
                        >
                    </span>
                    <span v-if="system.backup.latest" class="system-sub">{{
                        formatDateTime(system.backup.latest.created_at)
                    }}</span>
                </Link>
            </div>
        </NCard>

        <div
            v-if="can('activity-log.view') || can('roles.view')"
            class="grid-2"
            :class="{
                'grid-2--single':
                    !can('activity-log.view') || !can('roles.view'),
            }"
        >
            <NCard
                v-if="can('activity-log.view')"
                padding="0"
                class="activity-card"
            >
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
                        :verb="(a.action_label || a.action).toLowerCase()"
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

            <NCard v-if="can('roles.view')" padding="20px">
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
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: var(--kpi-gap, 16px);
}
.grid-2 {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 16px;
    margin-top: 16px;
}
.grid-2--single {
    grid-template-columns: 1fr;
}
.system-card {
    margin-top: 16px;
}
.system-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}
.system-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: inherit;
    text-decoration: none;
}
.system-item:hover {
    background: var(--surface-2);
}
.system-label {
    font-size: 12px;
    color: var(--text-muted);
}
.system-value {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
}
.system-sub {
    font-size: 12px;
    color: var(--text-muted);
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
