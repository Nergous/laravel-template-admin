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
import { activityVisual as visual } from "@/admin/activityVisuals";

type Option = { value: string; label: string };
type Filters = {
    action?: string | null;
    subject_type?: string | null;
    subject_id?: string | null;
    user_id?: string | null;
    impersonator_id?: string | null;
    date_from?: string | null;
    date_to?: string | null;
};

const props = defineProps({
    logs: { type: Object as PropType<Pagination<AuditLog>>, required: true },
    filters: { type: Object as PropType<Filters>, default: () => ({}) },
    actions: { type: Array as PropType<Option[]>, default: () => [] },
    subjectTypes: { type: Array as PropType<Option[]>, default: () => [] },
    actors: { type: Array as PropType<Option[]>, default: () => [] },
    // Administrators who acted "as" someone; the filter shows only when there are any.
    impersonators: { type: Array as PropType<Option[]>, default: () => [] },
    // Name of the entity picked by subject_type + subject_id (links from cards).
    subjectLabel: { type: String as PropType<string | null>, default: null },
    fieldLabels: {
        type: Object as PropType<Record<string, string>>,
        default: () => ({}),
    },
});

// Dates in the filters and the clear form are days in the display time zone.
const today = todayIso();

const action = ref(props.filters.action ?? "");
const subjectType = ref(props.filters.subject_type ?? "");
const subjectId = ref(props.filters.subject_id ?? "");
const userId = ref(props.filters.user_id ?? "");
const impersonatorId = ref(props.filters.impersonator_id ?? "");
const dateFrom = ref(props.filters.date_from ?? "");
const dateTo = ref(props.filters.date_to ?? "");

function currentParams() {
    return {
        action: action.value || undefined,
        subject_type: subjectType.value || undefined,
        subject_id: subjectId.value || undefined,
        user_id: userId.value || undefined,
        impersonator_id: impersonatorId.value || undefined,
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
const impersonatorOptions = computed(() => [
    { value: "", label: "Любой режим входа" },
    ...props.impersonators.map((o) => ({
        value: o.value,
        label: `Действовал ${o.label}`,
    })),
]);

const hasFilters = computed(() =>
    Object.values(currentParams()).some((v) => v !== undefined),
);

function resetFilters() {
    action.value = "";
    subjectType.value = "";
    subjectId.value = "";
    userId.value = "";
    impersonatorId.value = "";
    dateFrom.value = "";
    dateTo.value = "";
    reload({ page: 1 });
}

// A different type makes the selected entity meaningless.
function onTypeChange() {
    subjectId.value = "";
    reload({ page: 1 });
}

function clearSubject() {
    subjectId.value = "";
    reload({ page: 1 });
}

// Quick periods; days are counted in the display time zone like the inputs.
const presets = [
    { days: 0, label: "Сегодня" },
    { days: 6, label: "7 дней" },
    { days: 29, label: "30 дней" },
];
function shiftDays(iso: string, days: number): string {
    const d = new Date(`${iso}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() - days);
    return d.toISOString().slice(0, 10);
}
function applyPreset(days: number) {
    dateFrom.value = shiftDays(today, days);
    dateTo.value = today;
    reload({ page: 1 });
}
function isPreset(days: number) {
    return dateTo.value === today && dateFrom.value === shiftDays(today, days);
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
        return {
            field,
            label: props.fieldLabels[field] ?? field,
            oldValue,
            newValue,
            parts: diffParts(oldValue, newValue),
        };
    });
});

const hasChanges = computed(() => changeRows.value.length > 0);

// «—» for null/undefined/empty-string; everything else rendered as text.
function displayValue(value: unknown) {
    if (value === null || value === undefined || value === "") return "—";
    if (typeof value === "boolean") return value ? "да" : "нет";
    if (typeof value === "object") return JSON.stringify(value);
    return String(value);
}

type Segments = { same: boolean; text: string }[];

/**
 * Splits two values into unchanged and changed parts: lists by item, long
 * strings by the differing middle (common prefix and suffix stay plain).
 * Null when a plain side-by-side view is clearer.
 */
function diffParts(
    oldValue: unknown,
    newValue: unknown,
): { old: Segments; new: Segments } | null {
    if (Array.isArray(oldValue) && Array.isArray(newValue)) {
        const before = oldValue.map((v) => displayValue(v));
        const after = newValue.map((v) => displayValue(v));
        return {
            old: before.map((text) => ({ text, same: after.includes(text) })),
            new: after.map((text) => ({ text, same: before.includes(text) })),
        };
    }
    if (typeof oldValue !== "string" || typeof newValue !== "string")
        return null;
    if (Math.max(oldValue.length, newValue.length) < 24) return null;

    let start = 0;
    while (
        start < oldValue.length &&
        start < newValue.length &&
        oldValue[start] === newValue[start]
    )
        start++;
    let end = 0;
    while (
        end < oldValue.length - start &&
        end < newValue.length - start &&
        oldValue[oldValue.length - 1 - end] ===
            newValue[newValue.length - 1 - end]
    )
        end++;

    const split = (value: string): Segments =>
        [
            { same: true, text: value.slice(0, start) },
            { same: false, text: value.slice(start, value.length - end) },
            { same: true, text: value.slice(value.length - end) },
        ].filter((s) => s.text !== "");
    return { old: split(oldValue), new: split(newValue) };
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
                        @update:model-value="onTypeChange"
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
                    <NSelect
                        v-if="impersonators.length || impersonatorId"
                        v-model="impersonatorId"
                        :options="impersonatorOptions"
                        aria-label="Вход от имени"
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
                    <div
                        class="filters__presets"
                        role="group"
                        aria-label="Период"
                    >
                        <NButton
                            v-for="preset in presets"
                            :key="preset.days"
                            size="sm"
                            :variant="
                                isPreset(preset.days) ? 'primary' : 'ghost'
                            "
                            :aria-pressed="isPreset(preset.days)"
                            @click="applyPreset(preset.days)"
                            >{{ preset.label }}</NButton
                        >
                    </div>
                    <span v-if="subjectId" class="filters__chip">
                        Объект: {{ subjectLabel ?? `#${subjectId}` }}
                        <button
                            type="button"
                            class="filters__chip-x"
                            aria-label="Убрать фильтр по объекту"
                            @click="clearSubject"
                        >
                            ×
                        </button>
                    </span>
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
                                    <span :title="row.field">{{
                                        row.label
                                    }}</span>
                                </th>
                                <td
                                    class="diff__cell diff__cell--mono diff__cell--old"
                                >
                                    <template v-if="row.parts">
                                        <span
                                            v-for="(seg, i) in row.parts.old"
                                            :key="i"
                                            :class="{
                                                'diff__mark diff__mark--old':
                                                    !seg.same,
                                                diff__item: Array.isArray(
                                                    row.oldValue,
                                                ),
                                            }"
                                            >{{ seg.text }}</span
                                        >
                                        <span v-if="!row.parts.old.length"
                                            >—</span
                                        >
                                    </template>
                                    <template v-else>{{
                                        displayValue(row.oldValue)
                                    }}</template>
                                </td>
                                <td
                                    class="diff__cell diff__cell--mono diff__cell--new"
                                >
                                    <template v-if="row.parts">
                                        <span
                                            v-for="(seg, i) in row.parts.new"
                                            :key="i"
                                            :class="{
                                                'diff__mark diff__mark--new':
                                                    !seg.same,
                                                diff__item: Array.isArray(
                                                    row.newValue,
                                                ),
                                            }"
                                            >{{ seg.text }}</span
                                        >
                                        <span v-if="!row.parts.new.length"
                                            >—</span
                                        >
                                    </template>
                                    <template v-else>{{
                                        displayValue(row.newValue)
                                    }}</template>
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
/* NSelectWithSearch is a full-width block (width: 100%) by design, unlike the
   inline NSelect; in this wrapping toolbar size all selects to their content. */
.filters .filters__select {
    flex: none;
    width: auto;
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
.filters__presets {
    display: flex;
    gap: 4px;
}
.filters__chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 30px;
    padding: 0 6px 0 10px;
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent-ink);
    font-size: 12.5px;
    font-weight: 600;
}
.filters__chip-x {
    border: 0;
    background: transparent;
    color: inherit;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
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
.diff__cell--old {
    background: var(--danger-bg);
}
.diff__cell--new {
    background: var(--ok-bg);
}
.diff__mark {
    border-radius: 3px;
    font-weight: 700;
}
.diff__mark--old {
    background: color-mix(in srgb, var(--danger) 22%, transparent);
    text-decoration: line-through;
}
.diff__mark--new {
    background: color-mix(in srgb, var(--ok) 25%, transparent);
}
/* List values: one item per line. */
.diff__item {
    display: block;
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
