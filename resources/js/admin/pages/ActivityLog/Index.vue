<script setup lang="ts">
import { ref, computed } from "vue";
import type { PropType } from "vue";
import type { AuditLog, Pagination } from "@/admin/types";
import { Link, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NCard,
    NActivityRow,
    NPagination,
    NEmptyState,
    NDrawer,
    NModal,
    NFormField,
    NInput,
    NButton,
    NSelect,
    NSelectWithSearch,
} from "nergous-ui-vue";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
import { can } from "@/lib/can";
import { formatRelative, formatDateTime, todayIso } from "@/lib/format";

type Option = { value: string; label: string };
type Filters = {
    action?: string | null;
    subject_type?: string | null;
    user_id?: string | null;
    date_from?: string | null;
    date_to?: string | null;
};

const props = defineProps({
    logs: { type: Object as PropType<Pagination<AuditLog>>, required: true },
    filters: { type: Object as PropType<Filters>, default: () => ({}) },
    actions: { type: Array as PropType<Option[]>, default: () => [] },
    subjectTypes: { type: Array as PropType<Option[]>, default: () => [] },
    actors: { type: Array as PropType<Option[]>, default: () => [] },
});

// Dates in the filters and the clear form are days in the display time zone.
const today = todayIso();

const action = ref(props.filters.action ?? "");
const subjectType = ref(props.filters.subject_type ?? "");
const userId = ref(props.filters.user_id ?? "");
const dateFrom = ref(props.filters.date_from ?? "");
const dateTo = ref(props.filters.date_to ?? "");

function currentParams() {
    return {
        action: action.value || undefined,
        subject_type: subjectType.value || undefined,
        user_id: userId.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    };
}
const { reload } = useIndexFilters("/admin/activity-log", currentParams);

const actionOptions = computed(() => [
    { value: "", label: "Все действия" },
    ...props.actions,
]);
const typeOptions = computed(() => [
    { value: "", label: "Все объекты" },
    ...props.subjectTypes,
]);
const actorOptions = computed(() => [
    { value: "", label: "Все пользователи" },
    ...props.actors,
]);

const hasFilters = computed(() =>
    Object.values(currentParams()).some((v) => v !== undefined),
);

function resetFilters() {
    action.value = "";
    subjectType.value = "";
    userId.value = "";
    dateFrom.value = "";
    dateTo.value = "";
    reload({ page: 1 });
}

// The export uses the same filters as the list.
const exportUrl = computed(() => {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(currentParams())) {
        if (value) params.set(key, value);
    }
    const query = params.toString();
    return `/admin/activity-log/export${query ? `?${query}` : ""}`;
});

// Keep action visuals in the page; the activity component stays presentational.
const VISUAL: Record<
    string,
    { tone: "ok" | "info" | "danger" | "accent"; icon: string }
> = {
    created: { tone: "ok", icon: "plus" },
    updated: { tone: "info", icon: "edit" },
    deleted: { tone: "danger", icon: "trash" },
    force_deleted: { tone: "danger", icon: "trash" },
    restored: { tone: "ok", icon: "check" },
    duplicated: { tone: "accent", icon: "copy" },
    login: { tone: "info", icon: "user" },
    login_failed: { tone: "danger", icon: "alert-triangle" },
    cleared: { tone: "danger", icon: "eraser" },
    backup_created: { tone: "accent", icon: "shield" },
    backup_downloaded: { tone: "info", icon: "download" },
};
const FALLBACK = { tone: "info", icon: "edit" };

function visual(value: string) {
    return VISUAL[value] ?? FALLBACK;
}

function metaFor(log: AuditLog) {
    const n = Number(log.changesCount ?? 0);
    return n > 0 ? `${n} изм.` : "";
}

const detailOpen = ref(false);
const selected = ref<AuditLog | null>(null);

// Turn the server's field-to-pair map into rows for the diff table.
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
function displayValue(value: unknown) {
    if (value === null || value === undefined || value === "") return "—";
    if (typeof value === "object") return JSON.stringify(value);
    return String(value);
}

function openDetail(log: AuditLog) {
    selected.value = log;
    detailOpen.value = true;
}

const clearOpen = ref(false);
const clearForm = useForm({ before: "" });

function openClear() {
    clearForm.reset();
    clearForm.clearErrors();
    clearOpen.value = true;
}
function submitClear() {
    clearForm.delete("/admin/activity-log", {
        preserveScroll: true,
        onSuccess: () => {
            clearOpen.value = false;
        },
    });
}
</script>

<template>
    <AdminLayout title="Журнал действий" subtitle="Хронология событий">
        <div class="page">
            <div class="toolbar">
                <div class="filters" role="group" aria-label="Фильтры журнала">
                    <NSelect
                        v-model="action"
                        :options="actionOptions"
                        aria-label="Действие"
                        class="filters__select"
                        @update:model-value="reload({ page: 1 })"
                    />
                    <NSelect
                        v-model="subjectType"
                        :options="typeOptions"
                        aria-label="Тип объекта"
                        class="filters__select"
                        @update:model-value="reload({ page: 1 })"
                    />
                    <NSelectWithSearch
                        v-model="userId"
                        :options="actorOptions"
                        search-placeholder="Найти пользователя…"
                        no-results-text="Никого не найдено"
                        aria-label="Пользователь"
                        class="filters__select"
                        @update:model-value="reload({ page: 1 })"
                    />
                    <div class="filters__dates">
                        <NInput
                            v-model="dateFrom"
                            type="date"
                            :max="dateTo || today"
                            aria-label="С даты"
                            @update:model-value="reload({ page: 1 })"
                        />
                        <span class="filters__dash">—</span>
                        <NInput
                            v-model="dateTo"
                            type="date"
                            :min="dateFrom || undefined"
                            :max="today"
                            aria-label="По дату"
                            @update:model-value="reload({ page: 1 })"
                        />
                    </div>
                    <NButton
                        v-if="hasFilters"
                        variant="ghost"
                        icon="x"
                        @click="resetFilters"
                        >Сбросить</NButton
                    >
                </div>
                <div class="toolbar__actions">
                    <NButton
                        variant="secondary"
                        icon="download"
                        :as="'a'"
                        :href="exportUrl"
                        :disabled="logs.total === 0"
                        >Экспорт CSV</NButton
                    >
                    <NButton
                        v-if="can('activity-log.delete')"
                        variant="secondary"
                        icon="trash"
                        @click="openClear"
                        >Очистить журнал</NButton
                    >
                </div>
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
                    v-else-if="hasFilters"
                    icon="filter"
                    title="Ничего не найдено"
                    description="Нет событий по выбранным фильтрам."
                />
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
                    jumpable
                    prev-label="Назад"
                    next-label="Вперёд"
                    jump-label="Страница"
                    jump-button-label="Перейти"
                    total-label="из"
                    jump-error-label="Введите корректный номер страницы"
                    aria-label="Навигация по страницам"
                    @update:page="(p) => reload({ page: p })"
                />
            </div>
        </div>

        <NDrawer
            v-model="detailOpen"
            title="Событие журнала"
            :subtitle="selected ? formatDateTime(selected.createdAt) : ''"
            close-label="Закрыть"
        >
            <template v-if="selected">
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
                            <Link
                                v-if="selected.subjectUrl"
                                :href="selected.subjectUrl"
                                class="detail__link"
                                >{{ selected.subject || "—" }}</Link
                            >
                            <span v-else>{{ selected.subject || "—" }}</span>
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

        <NModal
            :model-value="clearOpen"
            title="Очистить журнал"
            width="440px"
            close-label="Закрыть"
            @update:model-value="clearOpen = $event"
        >
            <p class="clear__msg">
                Удалит все события раньше выбранной даты. События за выбранный
                день и позже останутся. Действие необратимо.
            </p>
            <NFormField
                label="Удалить события до даты"
                :error="clearForm.errors.before"
                required
            >
                <NInput
                    v-model="clearForm.before"
                    type="date"
                    :max="today"
                    :error="!!clearForm.errors.before"
                />
            </NFormField>

            <template #footer="{ close }">
                <NButton variant="secondary" block @click="close"
                    >Отмена</NButton
                >
                <NButton
                    variant="danger"
                    block
                    :loading="clearForm.processing"
                    @click="submitClear"
                    >Очистить</NButton
                >
            </template>
        </NModal>
    </AdminLayout>
</template>

<style scoped>
.toolbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.filters {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.filters__select {
    min-width: 180px;
}
.filters__dates {
    display: flex;
    align-items: center;
    gap: 6px;
}
.filters__dash {
    color: var(--text-3);
}
.toolbar__actions {
    display: flex;
    gap: 8px;
    flex: none;
}
.detail__link {
    color: var(--accent);
    font-weight: 700;
    text-decoration: none;
}
.detail__link:hover {
    text-decoration: underline;
}
.clear__msg {
    margin: 0 0 16px;
    color: var(--text-2);
    font-size: 14px;
    line-height: 1.5;
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
