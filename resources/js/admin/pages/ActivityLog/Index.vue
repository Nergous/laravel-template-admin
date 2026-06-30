<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NCard,
    NActivityRow,
    NPagination,
    NEmptyState,
    NDrawer,
    NButton,
} from "@/lib/nergous-cit";
import { formatRelative, formatDateTime } from "@/lib/format.js";

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

// Chip filter bar. Each chip maps to an `action` enum value; «Все» clears it.
const CHIPS = [
    { value: "", label: "Все" },
    { value: "created", label: "Создано" },
    { value: "updated", label: "Изменено" },
    { value: "deleted", label: "Удалено" },
    { value: "restored", label: "Восстановлено" },
    { value: "duplicated", label: "Дублировано" },
    { value: "force_deleted", label: "Удалено навсегда" },
];

// tone + icon per action. NActivityRow tones: ok | info | danger | warn | accent.
// NIcon set offers: plus, edit, trash, check, copy.
const VISUAL = {
    created: { tone: "ok", icon: "plus" },
    updated: { tone: "info", icon: "edit" },
    deleted: { tone: "danger", icon: "trash" },
    force_deleted: { tone: "danger", icon: "trash" },
    restored: { tone: "ok", icon: "check" },
    duplicated: { tone: "accent", icon: "copy" },
};
const FALLBACK = { tone: "info", icon: "edit" };

const activeAction = computed(() => props.filters?.action ?? "");

function visual(action) {
    return VISUAL[action] ?? FALLBACK;
}

function metaFor(log) {
    const n = Number(log.changesCount ?? 0);
    return n > 0 ? `${n} изм.` : "";
}

function selectAction(value) {
    router.get(
        "/admin/activity-log",
        { action: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function goToPage(page) {
    router.get(
        "/admin/activity-log",
        { action: activeAction.value || undefined, page },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/* ---------- detail drawer ---------- */
const detailOpen = ref(false);
const selected = ref(null);

// Normalize `changes` map → array of { field, oldValue, newValue } for the diff
// table. Backend shape: { field: [oldValue, newValue] } | null.
const changeRows = computed(() => {
    const map = selected.value?.changes;
    if (!map || typeof map !== "object") return [];
    return Object.entries(map).map(([field, pair]) => {
        const [oldValue, newValue] = Array.isArray(pair) ? pair : [null, pair];
        return { field, oldValue, newValue };
    });
});

const hasChanges = computed(() => changeRows.value.length > 0);

// «—» for null/undefined/empty-string; everything else rendered as text.
function displayValue(value) {
    if (value === null || value === undefined || value === "") return "—";
    if (typeof value === "object") return JSON.stringify(value);
    return String(value);
}

function openDetail(log) {
    selected.value = log;
    detailOpen.value = true;
}
</script>

<template>
    <AdminLayout title="Журнал действий" subtitle="Хронология событий">
        <div class="page">
            <div class="chips" role="group" aria-label="Фильтр по действию">
                <button
                    v-for="chip in CHIPS"
                    :key="chip.value || 'all'"
                    type="button"
                    class="chip"
                    :class="{ 'chip--on': activeAction === chip.value }"
                    :aria-pressed="activeAction === chip.value"
                    @click="selectAction(chip.value)"
                >
                    {{ chip.label }}
                </button>
            </div>

            <NCard padding="0">
                <ul v-if="logs.data.length" class="feed">
                    <li v-for="log in logs.data" :key="log.id">
                        <div
                            class="feed-row"
                            role="button"
                            tabindex="0"
                            :aria-label="`Открыть событие: ${log.actor} ${log.actionLabel} ${log.subject}`"
                            @click="openDetail(log)"
                            @keydown.enter.prevent="openDetail(log)"
                            @keydown.space.prevent="openDetail(log)"
                        >
                            <NActivityRow
                                :tone="visual(log.action).tone"
                                :icon="visual(log.action).icon"
                                :actor="log.actor"
                                :verb="log.actionLabel"
                                :object="log.subject"
                                :tag="log.subjectType"
                                :time="formatRelative(log.createdAt)"
                                :meta="metaFor(log)"
                            />
                        </div>
                    </li>
                </ul>
                <NEmptyState
                    v-else
                    icon="activity"
                    title="Событий пока нет"
                    description="Действия пользователей будут появляться здесь по мере их выполнения."
                />
            </NCard>

            <div v-if="logs.last_page > 1" class="page__pager">
                <NPagination
                    :page="logs.current_page"
                    :pages="logs.last_page"
                    prev-label="Назад"
                    next-label="Вперёд"
                    aria-label="Навигация по страницам"
                    @update:page="goToPage"
                />
            </div>
        </div>

        <!-- event detail drawer -->
        <NDrawer
            v-model="detailOpen"
            title="Событие журнала"
            :subtitle="selected ? formatDateTime(selected.createdAt) : ''"
            close-label="Закрыть"
        >
            <template v-if="selected">
                <!-- summary block -->
                <dl class="detail">
                    <div class="detail__row">
                        <dt class="detail__key">Кто</dt>
                        <dd class="detail__val">{{ selected.actor || "—" }}</dd>
                    </div>
                    <div class="detail__row">
                        <dt class="detail__key">Действие</dt>
                        <dd class="detail__val">
                            {{ selected.actionLabel || "—" }}
                        </dd>
                    </div>
                    <div class="detail__row">
                        <dt class="detail__key">Объект</dt>
                        <dd class="detail__val detail__val--object">
                            <span>{{ selected.subject || "—" }}</span>
                            <span
                                v-if="selected.subjectType"
                                class="detail__tag"
                                >{{ selected.subjectType }}</span
                            >
                        </dd>
                    </div>
                    <div class="detail__row">
                        <dt class="detail__key">Когда</dt>
                        <dd class="detail__val detail__val--mono">
                            {{ formatDateTime(selected.createdAt) }}
                        </dd>
                    </div>
                </dl>

                <!-- diff -->
                <section class="diff">
                    <h4 id="diff-title" class="diff__title">Изменения</h4>
                    <table
                        v-if="hasChanges"
                        class="diff__table"
                        aria-labelledby="diff-title"
                    >
                        <thead>
                            <tr>
                                <th scope="col">Поле</th>
                                <th scope="col">Было</th>
                                <th scope="col">Стало</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in changeRows" :key="row.field">
                                <th scope="row" class="diff__field">
                                    {{ row.field }}
                                </th>
                                <td class="diff__cell diff__cell--mono">
                                    {{ displayValue(row.oldValue) }}
                                </td>
                                <td class="diff__cell diff__cell--mono">
                                    {{ displayValue(row.newValue) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="diff__empty">Изменения не зафиксированы</p>
                </section>
            </template>

            <template #footer="{ close }">
                <NButton variant="primary" class="detail-foot" @click="close"
                    >Закрыть</NButton
                >
            </template>
        </NDrawer>
    </AdminLayout>
</template>

<style scoped>
/* .page / .page__pager — общие утилиты в resources/js/admin/styles.css */
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.chip {
    height: 32px;
    padding: 0 14px;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-2);
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    transition:
        background-color 0.14s ease,
        border-color 0.14s ease,
        color 0.14s ease;
}
.chip:hover:not(.chip--on) {
    background: var(--surface-3);
    border-color: var(--text-3);
}
.chip--on {
    background: var(--accent-soft);
    border-color: transparent;
    color: var(--accent);
}
.chip:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}

.feed {
    margin: 0;
    padding: 0;
    list-style: none;
}
/* clickable feed row wrapper around presentational NActivityRow */
.feed-row {
    cursor: pointer;
    transition: background-color 0.14s ease;
}
.feed-row:hover {
    background: var(--surface-3);
}
.feed-row:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: -2px;
    border-radius: var(--radius-md, 8px);
}

/* ----- drawer detail ----- */
.detail {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin: 0;
}
.detail__row {
    display: grid;
    grid-template-columns: 96px 1fr;
    gap: 12px;
    align-items: baseline;
}
.detail__key {
    margin: 0;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-3);
}
.detail__val {
    margin: 0;
    font-size: 13.5px;
    color: var(--text);
    word-break: break-word;
}
.detail__val--object {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.detail__val--mono {
    font-family: var(--font-mono);
    font-size: 13px;
}
.detail__tag {
    height: 20px;
    padding: 0 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-3);
    background: var(--surface-3);
    display: inline-flex;
    align-items: center;
}

.diff__title {
    margin: 0 0 10px;
    font-size: 13px;
    font-weight: 800;
    color: var(--text);
}
.diff__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
}
.diff__table th,
.diff__table td {
    text-align: left;
    padding: 7px 9px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
}
.diff__table thead th {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.diff__field {
    font-weight: 700;
    color: var(--text-2);
    white-space: nowrap;
}
.diff__cell {
    color: var(--text);
    word-break: break-word;
}
.diff__cell--mono {
    font-family: var(--font-mono);
}
.diff__empty {
    margin: 0;
    font-size: 13px;
    color: var(--text-3);
}
.detail-foot {
    flex: 1;
}
</style>
