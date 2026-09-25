<script setup lang="ts">
import { computed } from "vue";
import type { PropType } from "vue";
import { router } from "@inertiajs/vue3";
import {
    NAlert,
    NBadge,
    NButton,
    NCard,
    NEmptyState,
    NIcon,
    NPagination,
    NStatCard,
} from "nergous-ui-vue";
import type { Pagination } from "@/admin/types";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { useConfirm } from "@/admin/composables/useConfirm";
import { can } from "@/lib/can";
import { formatDateTime, formatNumber, formatRelative } from "@/lib/format";

interface QueueSummary {
    pending: number | null;
    oldest_pending_at: string | null;
    failed: number;
}

interface FailedJob {
    id: string;
    uuid: string | null;
    connection: string;
    queue: string;
    name: string;
    exception: string;
    failed_at: string | null;
}

type QueueAction = "retry" | "delete" | "retry-all" | "flush";

const props = defineProps({
    summary: { type: Object as PropType<QueueSummary>, required: true },
    connection: { type: String, default: "" },
    driver: { type: String as PropType<string | null>, default: null },
    failedJobs: {
        type: Object as PropType<Pagination<FailedJob>>,
        required: true,
    },
});

/** A backlog older than this means the worker is likely down (same as /up). */
const STALE_MS = 15 * 60 * 1000;

const isDatabase = computed(() => props.summary.pending !== null);
const stale = computed(
    () =>
        !!props.summary.oldest_pending_at &&
        Date.now() - new Date(props.summary.oldest_pending_at).getTime() >
            STALE_MS,
);
const canManage = computed(() => can("queue.manage"));

/** Class name without the namespace; the full name stays in the tooltip. */
function shortName(name: string): string {
    return name.split("\\").pop() || name;
}

const confirm = useConfirm<{ action: QueueAction; job?: FailedJob }>();

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
                title: "Повторить все задачи?",
                message: `Все упавшие задачи (${formatNumber(props.summary.failed)}) вернутся в свои очереди.`,
                label: "Повторить все",
                danger: false,
            };
        default:
            return {
                title: "Удалить все задачи?",
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

function goToPage(page: number) {
    router.get(
        "/admin/queue",
        { page },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <AdminLayout title="Очередь задач" subtitle="Ожидающие и упавшие задачи">
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

            <NAlert v-if="stale" tone="warn" title="Очередь не разбирается">
                Задача ждёт дольше 15 минут. Проверьте, что запущен воркер
                (<code>php artisan queue:work</code>).
            </NAlert>

            <NCard padding="0">
                <div class="head">
                    <h2 class="head__title">Упавшие задачи</h2>
                    <div
                        v-if="canManage && summary.failed > 0"
                        class="head__actions"
                    >
                        <NButton
                            variant="secondary"
                            size="sm"
                            icon="bolt"
                            @click="confirm.ask({ action: 'retry-all' })"
                            >Повторить все</NButton
                        >
                        <NButton
                            variant="ghost"
                            tone="danger"
                            size="sm"
                            icon="trash"
                            @click="confirm.ask({ action: 'flush' })"
                            >Удалить все</NButton
                        >
                    </div>
                </div>

                <ul v-if="failedJobs.data.length" class="list">
                    <li
                        v-for="job in failedJobs.data"
                        :key="job.id"
                        class="row"
                    >
                        <span class="row__ico"
                            ><NIcon name="alert-triangle" :size="18"
                        /></span>
                        <div class="row__main">
                            <span class="row__head">
                                <span class="row__name" :title="job.name">{{
                                    shortName(job.name)
                                }}</span>
                                <NBadge size="sm" pill>{{ job.queue }}</NBadge>
                            </span>
                            <span class="row__error" :title="job.exception">{{
                                job.exception || "Без описания ошибки"
                            }}</span>
                            <span class="row__meta">
                                {{ formatDateTime(job.failed_at) }} ·
                                {{ formatRelative(job.failed_at) }} ·
                                {{ job.connection }}
                            </span>
                        </div>
                        <div v-if="canManage && job.uuid" class="row__actions">
                            <NButton
                                variant="secondary"
                                size="sm"
                                icon="bolt"
                                @click="confirm.ask({ action: 'retry', job })"
                                >Повторить</NButton
                            >
                            <NButton
                                variant="ghost"
                                tone="danger"
                                size="sm"
                                icon="trash"
                                :aria-label="`Удалить задачу ${shortName(job.name)}`"
                                @click="confirm.ask({ action: 'delete', job })"
                            />
                        </div>
                    </li>
                </ul>
                <NEmptyState
                    v-else
                    icon="check"
                    title="Упавших задач нет"
                    description="Если задача завершится ошибкой после всех попыток, она появится здесь."
                />
            </NCard>

            <div v-if="failedJobs.last_page > 1" class="page__pager">
                <NPagination
                    :page="failedJobs.current_page"
                    :pages="failedJobs.last_page"
                    prev-label="Назад"
                    next-label="Вперёд"
                    total-label="из"
                    aria-label="Навигация по страницам"
                    @update:page="goToPage"
                />
            </div>
        </div>

        <ConfirmModal
            :open="confirm.open"
            :loading="confirm.loading"
            :title="confirmText.title"
            :message="confirmText.message"
            :confirm-label="confirmText.label"
            :danger="confirmText.danger"
            @confirm="runConfirmed"
            @cancel="confirm.close"
            @update:open="confirm.open = $event"
        />
    </AdminLayout>
</template>

<style scoped>
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--kpi-gap, 16px);
}

.head {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}
.head__title {
    flex: 1;
    margin: 0;
    font-size: calc(var(--fs, 14px) + 1px);
    font-weight: 700;
    color: var(--text);
}
.head__actions {
    display: flex;
    gap: 6px;
}

.list {
    margin: 0;
    padding: 0;
    list-style: none;
}
.row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: var(--row-pad, 14px) 16px;
    border-bottom: 1px solid var(--border);
}
.row:last-child {
    border-bottom: 0;
}
.row__ico {
    display: inline-flex;
    color: var(--danger, var(--text-3));
    flex: none;
}
.row__main {
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex: 1;
    min-width: 0;
}
.row__head {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.row__name {
    font-family: var(--font-mono);
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.row__error {
    font-size: 12.5px;
    color: var(--text-2);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.row__meta {
    font-size: 12px;
    color: var(--text-3);
}
.row__actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex: none;
}
code {
    font-family: var(--font-mono);
    font-size: 12.5px;
}
</style>
