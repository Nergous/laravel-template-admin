<script setup lang="ts">
import { computed } from "vue";
import type { PropType } from "vue";
import { Link } from "@inertiajs/vue3";
import { usePageHeader } from "@/admin/composables/usePageHeader";
import {
    NButton,
    NCard,
    NStatCard,
    NActivityRow,
    NEmptyState,
    NIcon,
} from "nergous-ui-vue";
import { can } from "@/lib/can";
import { formatBytes, formatNumber, formatRelative } from "@/lib/format";
import { activityVisual } from "@/admin/activityVisuals";
import type { AuditLog } from "@/admin/types";

type StatCard = {
    value: number;
    sub: string;
    bytes?: number;
    spark?: number[];
    /** Media card: images without alternative text. */
    no_alt?: number;
};

type AttentionItem = {
    key: string;
    tone: "danger" | "warn" | "info";
    label: string;
    value: string;
    url: string;
};

const props = defineProps({
    stats: {
        type: Object as PropType<Record<string, StatCard>>,
        required: true,
    },
    /** Problems to act on; empty when everything is fine. */
    attention: {
        type: Array as PropType<AttentionItem[]>,
        default: () => [],
    },
    recentActivity: {
        type: Array as PropType<AuditLog[]>,
        default: () => [],
    },
});

const cards = [
    { key: "media", label: "Медиафайлы", icon: "asset", href: "/admin/media" },
    {
        key: "users",
        label: "Пользователи",
        icon: "users",
        href: "/admin/users",
    },
    {
        key: "logins",
        label: "Входы за 24 ч",
        icon: "activity",
        href: "/admin/activity-log",
    },
];

// Only the cards the server sent (each one is gated by its section permission).
const visibleCards = computed(() => cards.filter((c) => props.stats[c.key]));

// The media card opens the images to fix while some lack alternative text.
const cardHref = (c: (typeof cards)[number]) =>
    c.key === "media" && (props.stats.media?.no_alt ?? 0) > 0
        ? "/admin/media?usage=no_alt"
        : c.href;

// Frequent actions above the cards, each shown with its permission.
const quickActions = computed(() =>
    [
        {
            perm: "users.create",
            label: "Создать пользователя",
            icon: "plus",
            href: "/admin/users/create",
            primary: true,
        },
        {
            perm: "media.upload",
            label: "Загрузить файлы",
            icon: "upload",
            href: "/admin/media",
            primary: false,
        },
    ].filter((action) => can(action.perm)),
);

const attentionIcon: Record<AttentionItem["tone"], string> = {
    danger: "alert-triangle",
    warn: "alert-triangle",
    info: "bell",
};

const cardSub = (s: StatCard) =>
    s.bytes !== undefined ? `${formatBytes(s.bytes)} · ${s.sub}` : s.sub;

// Tone and icon per action; the verb comes from the server (lang/ru/activity.php).
const actOf = (a: AuditLog) => activityVisual(a.action);
const actMeta = (a: AuditLog) =>
    a.changes_count ? `${a.changes_count} изм.` : "";

usePageHeader(() => ({
    title: "Главная",
    subtitle: "Обзор рабочего пространства",
}));
</script>

<template>
    <div v-if="quickActions.length" class="dash-actions">
        <NButton
            v-for="action in quickActions"
            :key="action.href"
            :as="Link"
            :href="action.href"
            :variant="action.primary ? 'primary' : 'secondary'"
            :icon="action.icon"
            >{{ action.label }}</NButton
        >
    </div>

    <div class="kpi-grid">
        <Link
            v-for="c in visibleCards"
            :key="c.key"
            :href="cardHref(c)"
            class="kpi-link"
        >
            <NStatCard
                :label="c.label"
                :icon="c.icon"
                :value="formatNumber(stats[c.key].value)"
                :sub="cardSub(stats[c.key])"
                :spark="stats[c.key].spark"
            />
        </Link>
    </div>

    <NCard v-if="attention.length" padding="20px" class="section-card">
        <div class="card-head">
            <h2 class="card-title">Требует внимания</h2>
        </div>
        <ul class="attention-list">
            <li v-for="item in attention" :key="item.key">
                <Link
                    :href="item.url"
                    class="attention-item"
                    :class="'attention-item--' + item.tone"
                >
                    <span class="attention-item__icon">
                        <NIcon :name="attentionIcon[item.tone]" :size="16" />
                    </span>
                    <span class="attention-item__label">{{ item.label }}</span>
                    <span class="attention-item__value">{{ item.value }}</span>
                    <NIcon
                        name="chevron-right"
                        :size="16"
                        class="attention-item__go"
                    />
                </Link>
            </li>
        </ul>
    </NCard>

    <NCard
        v-if="can('activity-log.view')"
        padding="0"
        class="activity-card section-card"
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
                :actor="a.actor"
                :verb="(a.action_label || a.action).toLowerCase()"
                :object="a.subject"
                :tag="a.subject_type"
                :time="formatRelative(a.created_at)"
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
</template>

<style scoped>
.dash-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 16px;
}
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: var(--kpi-gap, 16px);
}
.kpi-link {
    display: flex;
    color: inherit;
    text-decoration: none;
    border-radius: var(--radius-lg, 12px);
}
/* Cards in a row share one height (the logins card is taller due to its sparkline). */
.kpi-link :deep(.n-stat) {
    flex: 1;
    min-width: 0;
    /* NStatCard animates only its padding; keep that and fade the hover border. */
    transition:
        padding 0.18s ease,
        border-color 0.15s ease;
}
.kpi-link:hover :deep(.n-stat) {
    border-color: var(--accent);
}
.section-card {
    margin-top: 16px;
}
.attention-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.attention-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 9px 8px;
    margin: 0 -8px;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
}
.attention-item:hover {
    background: var(--surface-2);
}
.attention-item:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: -2px;
}
.attention-item__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    flex: none;
    border-radius: 8px;
}
.attention-item--danger .attention-item__icon {
    background: var(--danger-bg);
    color: var(--danger);
}
.attention-item--warn .attention-item__icon {
    background: var(--warn-bg);
    color: var(--warn);
}
.attention-item--info .attention-item__icon {
    background: var(--accent-soft);
    color: var(--accent);
}
.attention-item__label {
    flex: 1;
    min-width: 0;
    font-weight: 600;
}
.attention-item__value {
    color: var(--text-2);
    font-size: 13px;
    white-space: nowrap;
}
.attention-item__go {
    color: var(--text-3);
}
@media (max-width: 920px) {
    .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
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
</style>
