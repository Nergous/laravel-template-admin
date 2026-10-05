<script setup lang="ts">
import { computed, ref } from "vue";
import type { PropType } from "vue";
import { router } from "@inertiajs/vue3";
import {
    NAlert,
    NBadge,
    NButton,
    NDataTable,
    NEmptyState,
    NPagination,
    NSegmented,
    NStatCard,
    NConfirmDialog,
    useConfirm,
} from "nergous-ui-vue";
import type { Column, Row, Tone } from "nergous-ui-vue";
import type { Pagination } from "@/admin/types";
import { usePageHeader } from "@/admin/composables/usePageHeader";
import { useIndexFilters } from "@/admin/composables/useIndexFilters";
import { can } from "@/lib/can";
import {
    formatDateTime,
    formatNumber,
    formatRelative,
    pluralize,
} from "@/lib/format";

interface QueueSummary {
    pending: number | null;
    oldest_pending_at: string | null;
    failed: number;
}

type JobStatus = "running" | "pending" | "delayed" | "failed";

/** Queued statuses are null when the driver cannot list the queue. */
type StatusCounts = Record<Exclude<JobStatus, "failed">, number | null> & {
    failed: number;
};

interface QueueJob {
    [key: string]: unknown;
    id: string;
    uuid: string | null;
    status: JobStatus;
    connection: string;
    queue: string;
    name: string;
    attempts: number | null;
    exception: string | null;
    created_at: string | null;
    available_at: string | null;
    reserved_at: string | null;
    failed_at: string | null;
}

type QueueAction = "retry" | "delete" | "retry-all" | "flush";

const props = defineProps({
    summary: { type: Object as PropType<QueueSummary>, required: true },
    // A runnable job waited longer than stalledAfterMinutes (same rule as /up).
    stalled: { type: Boolean, default: false },
    stalledAfterMinutes: { type: Number, default: 15 },
    counts: { type: Object as PropType<StatusCounts>, required: true },
    status: { type: String as PropType<JobStatus | null>, default: null },
    connection: { type: String, default: "" },
    driver: { type: String as PropType<string | null>, default: null },
    jobs: {
        type: Object as PropType<Pagination<QueueJob>>,
        required: true,
    },
});

const STATUS: Record<JobStatus, { label: string; tone: Tone }> = {
    running: { label: "Выполняется", tone: "info" },
    pending: { label: "Ожидает", tone: "neutral" },
    delayed: { label: "Отложена", tone: "accent" },
    failed: { label: "Упала", tone: "danger" },
};

const isDatabase = computed(() => props.summary.pending !== null);
const stalledText = computed(
    () =>
        `${props.stalledAfterMinutes} ${pluralize(props.stalledAfterMinutes, "минуты", "минут", "минут")}`,
);
const canManage = computed(() => can("queue.manage"));

const filter = ref<string>(props.status ?? "all");
const { reload } = useIndexFilters("/admin/queue", () => ({
    status: filter.value === "all" ? undefined : filter.value,
}));

const filterOptions = computed(() => {
    const statuses = (Object.keys(STATUS) as JobStatus[]).filter(
        (status) => props.counts[status] !== null,
    );
    const total = statuses.reduce(
        (sum, status) => sum + (props.counts[status] ?? 0),
        0,
    );
    return [
        { value: "all", label: `Все (${formatNumber(total)})` },
        ...statuses.map((status) => ({
            value: status,
            label: `${STATUS[status].label} (${formatNumber(props.counts[status])})`,
        })),
    ];
});

const columns = computed<Column[]>(() => [
    { key: "name", label: "Задача" },
    { key: "status", label: "Статус", width: "150px" },
    { key: "queue", label: "Очередь", width: "130px" },
    { key: "attempts", label: "Попытки", width: "100px", align: "center" },
    { key: "time", label: "Время", width: "180px" },
    ...(canManage.value
        ? [
              {
                  key: "actions",
                  label: "Действия",
                  width: "110px",
                  align: "center" as const,
              },
          ]
        : []),
]);

const jobRow = (row: Row): QueueJob => row as QueueJob;

/** Class name without the namespace; the full name stays in the tooltip. */
function shortName(name: string): string {
    return name.split("\\").pop() || name;
}

/** The moment that matters for the job's status, with a caption. */
function timeOf(job: QueueJob): { caption: string; at: string | null } {
    switch (job.status) {
        case "running":
            return { caption: "Начата", at: job.reserved_at };
        case "pending":
            return { caption: "Ждёт с", at: job.available_at };
        case "delayed":
            return { caption: "Запуск", at: job.available_at };
        default:
            return { caption: "Упала", at: job.failed_at };
    }
}

const confirm = useConfirm<{ action: QueueAction; job?: QueueJob }>();

const confirmText = computed(() => {
    const job = confirm.payload?.job;
    switch (confirm.payload?.action) {
        case "retry":
            return {
                title: "Повторить задачу?",
                message: `Задача «${shortName(job?.name ?? "")}» вернётся в очередь «${job?.queue}» и будет выполнена заново.`,
                label: "Повторить",
                danger: false,
            };
        case "delete":
            return {
                title: "Удалить задачу?",
                message: `Задача «${shortName(job?.name ?? "")}» будет удалена из списка упавших без повторного запуска.`,
                label: "Удалить",
                danger: true,
            };
        case "retry-all":
            return {
                title: "Повторить все упавшие задачи?",
                message: `Все упавшие задачи (${formatNumber(props.summary.failed)}) вернутся в свои очереди.`,
                label: "Повторить все",
                danger: false,
            };
        default:
            return {
                title: "Удалить все упавшие задачи?",
                message: `Все упавшие задачи (${formatNumber(props.summary.failed)}) будут удалены без повторного запуска.`,
                label: "Удалить все",
                danger: true,
            };
    }
});

function runConfirmed() {
    const payload = confirm.payload;
    if (!payload) return;
    const uuid = payload.job?.uuid ?? "";
    const options = {
        preserveScroll: true,
        onFinish: () => confirm.close(),
    };
    confirm.loading = true;

    switch (payload.action) {
        case "retry":
            router.post(`/admin/queue/failed/${uuid}/retry`, {}, options);
            break;
        case "delete":
            router.delete(`/admin/queue/failed/${uuid}`, options);
            break;
        case "retry-all":
            router.post("/admin/queue/failed/retry-all", {}, options);
            break;
        case "flush":
            router.delete("/admin/queue/failed", options);
            break;
    }
}

usePageHeader(() => ({
    title: "Очередь задач",
    subtitle: "Задачи в очереди и их статусы",
}));
</script>

<template>
    <div class="page">
        <div class="stats">
            <NStatCard
                label="Ожидают обработки"
                icon="layers"
                :value="isDatabase ? formatNumber(summary.pending) : 'н/д'"
                :sub="
                    isDatabase
                        ? `соединение ${connection}`
                        : `драйвер ${driver ?? connection} не показывает очередь`
                "
            />
            <NStatCard
                label="Самая старая задача"
                icon="calendar"
                :value="
                    !isDatabase
                        ? 'н/д'
                        : summary.oldest_pending_at
                          ? formatRelative(summary.oldest_pending_at)
                          : '—'
                "
                :sub="
                    summary.oldest_pending_at
                        ? formatDateTime(summary.oldest_pending_at)
                        : isDatabase
                          ? 'очередь пуста'
                          : 'доступно для драйвера database'
                "
            />
            <NStatCard
                label="Упавшие"
                icon="alert-triangle"
                :value="formatNumber(summary.failed)"
                sub="ждут повтора или удаления"
            />
        </div>

        <NAlert v-if="stalled" tone="warn" title="Очередь не разбирается">
            Задача ждёт дольше {{ stalledText }}. Проверьте, что запущен воркер
            (<code>php artisan queue:work</code>).
        </NAlert>

        <div class="toolbar">
            <NSegmented
                v-model="filter"
                :options="filterOptions"
                aria-label="Фильтр по статусу"
                @update:model-value="reload({ page: 1 })"
            />
            <div
                v-if="canManage && summary.failed > 0"
                class="toolbar__actions"
            >
                <NButton
                    variant="secondary"
                    size="sm"
                    icon="bolt"
                    @click="confirm.ask({ action: 'retry-all' })"
                    >Повторить упавшие</NButton
                >
                <NButton
                    variant="ghost"
                    tone="danger"
                    size="sm"
                    icon="trash"
                    @click="confirm.ask({ action: 'flush' })"
                    >Удалить упавшие</NButton
                >
            </div>
        </div>

        <NDataTable
            :columns="columns"
            :rows="jobs.data"
            :page-size="0"
            empty-text="Нет задач"
            stacked
        >
            <template #cell-name="{ row }">
                <div class="job">
                    <span class="job__name" :title="jobRow(row).name">{{
                        shortName(jobRow(row).name)
                    }}</span>
                    <span
                        v-if="jobRow(row).status === 'failed'"
                        class="job__error"
                        :title="jobRow(row).exception ?? ''"
                        >{{
                            jobRow(row).exception || "Без описания ошибки"
                        }}</span
                    >
                </div>
            </template>

            <template #cell-status="{ row }">
                <NBadge :tone="STATUS[jobRow(row).status].tone" dot pill>{{
                    STATUS[jobRow(row).status].label
                }}</NBadge>
            </template>

            <template #cell-queue="{ row }">
                <span class="mono" :title="jobRow(row).connection">{{
                    jobRow(row).queue
                }}</span>
            </template>

            <template #cell-attempts="{ row }">
                {{ jobRow(row).attempts ?? "—" }}
            </template>

            <template #cell-time="{ row }">
                <div
                    class="when"
                    :title="formatDateTime(timeOf(jobRow(row)).at)"
                >
                    <span class="when__caption">{{
                        timeOf(jobRow(row)).caption
                    }}</span>
                    <span>{{ formatRelative(timeOf(jobRow(row)).at) }}</span>
                </div>
            </template>

            <template #cell-actions="{ row }">
                <div
                    v-if="jobRow(row).uuid"
                    class="row-actions row-actions--center"
                >
                    <NButton
                        variant="ghost"
                        tone="accent"
                        size="sm"
                        icon="bolt"
                        class="row-actions__btn"
                        :aria-label="`Повторить задачу ${shortName(jobRow(row).name)}`"
                        @click="
                            confirm.ask({
                                action: 'retry',
                                job: jobRow(row),
                            })
                        "
                    />
                    <NButton
                        variant="ghost"
                        tone="danger"
                        size="sm"
                        icon="trash"
                        class="row-actions__btn"
                        :aria-label="`Удалить задачу ${shortName(jobRow(row).name)}`"
                        @click="
                            confirm.ask({
                                action: 'delete',
                                job: jobRow(row),
                            })
                        "
                    />
                </div>
            </template>

            <template #empty>
                <NEmptyState
                    icon="check"
                    title="Задач нет"
                    :description="
                        isDatabase
                            ? 'Здесь появятся задачи, которые ждут, выполняются или завершились ошибкой.'
                            : 'Этот драйвер очереди не показывает ожидающие задачи. Здесь появятся задачи, завершившиеся ошибкой.'
                    "
                />
            </template>
        </NDataTable>

        <div v-if="jobs.last_page > 1" class="page__pager">
            <NPagination
                :page="jobs.current_page"
                :pages="jobs.last_page"
                @update:page="(page) => reload({ page })"
            />
        </div>
    </div>

    <NConfirmDialog
        v-model="confirm.open"
        :loading="confirm.loading"
        :title="confirmText.title"
        :message="confirmText.message"
        :confirm-label="confirmText.label"
        :danger="confirmText.danger"
        @confirm="runConfirmed"
    />
</template>

<style scoped>
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--kpi-gap, 16px);
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.toolbar__actions {
    display: flex;
    gap: 6px;
}

.job {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}
.job__name {
    font-family: var(--font-mono);
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.job__error {
    font-size: 12.5px;
    color: var(--text-2);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mono {
    font-family: var(--font-mono);
    font-size: 12.5px;
}
.when {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 13px;
}
.when__caption {
    font-size: 12px;
    color: var(--text-3);
}
code {
    font-family: var(--font-mono);
    font-size: 12.5px;
}
</style>
