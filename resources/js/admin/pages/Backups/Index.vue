<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import type { PropType } from "vue";
import { router } from "@inertiajs/vue3";
import {
    NAlert,
    NBadge,
    NButton,
    NCard,
    NEmptyState,
    NIcon,
    NSpinner,
} from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import { useConfirm } from "@/admin/composables/useConfirm";
import { can } from "@/lib/can";
import { formatBytes, formatDateTime, formatRelative } from "@/lib/format";

interface BackupFile {
    name: string;
    size: number;
    created_at: string;
    kind: string;
    encrypted: boolean;
}

const props = defineProps({
    backups: { type: Array as PropType<BackupFile[]>, default: () => [] },
    driver: { type: String, default: "" },
    encrypted: { type: Boolean, default: false },
    offsite: { type: String as PropType<string | null>, default: null },
    keep: {
        type: Object as PropType<{ scheduled: number; manual: number }>,
        default: () => ({ scheduled: 7, manual: 5 }),
    },
    // A manual dump is queued or running (CreateBackup job).
    pending: { type: Boolean, default: false },
    lastFailure: {
        type: Object as PropType<{ message: string; at: string } | null>,
        default: null,
    },
});

const kindLabels: Record<string, string> = {
    scheduled: "плановая",
    manual: "ручная",
    prerestore: "до восстановления",
};

/** "7 последних", or "все" when rotation is disabled for the kind. */
function keepText(count: number): string {
    return count > 0 ? `${count} последних` : "все";
}

const retention = computed(
    () =>
        `Хранятся плановые — ${keepText(props.keep.scheduled)}, ручные — ${keepText(props.keep.manual)}.`,
);

const creating = ref(false);

// While a queued dump is pending, reload the list every few seconds.
const POLL_MS = 4000;
let pollTimer: ReturnType<typeof setTimeout> | null = null;
function schedulePoll() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
    if (!props.pending) return;
    pollTimer = setTimeout(() => {
        router.reload({
            only: ["backups", "pending", "lastFailure"],
            onFinish: schedulePoll,
        });
    }, POLL_MS);
}
watch(() => props.pending, schedulePoll, { immediate: true });
onBeforeUnmount(() => {
    if (pollTimer) clearTimeout(pollTimer);
});

function createBackup() {
    creating.value = true;
    router.post(
        "/admin/backups",
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                creating.value = false;
            },
        },
    );
}

const del = useConfirm<BackupFile>();
function confirmDelete() {
    if (!del.payload) return;
    del.loading = true;
    router.delete(`/admin/backups/${encodeURIComponent(del.payload.name)}`, {
        preserveScroll: true,
        onFinish: () => del.close(),
    });
}
</script>

<template>
    <AdminLayout title="Резервные копии" subtitle="Дампы базы данных">
        <div class="page">
            <div class="intro">
                <span class="intro__ico"
                    ><NIcon name="shield" :size="18"
                /></span>
                <p class="intro__text">
                    Плановые копии создаёт команда <code>app:db-backup</code> по
                    расписанию, ручные — кнопка справа. {{ retention }}
                    <template v-if="encrypted">
                        Копии зашифрованы ключом
                        <code>BACKUP_ENCRYPTION_KEY</code>, расшифровка —
                        <code>app:db-backup-decrypt</code>.
                    </template>
                    <template v-else>
                        Файл содержит все учётные записи и настройки —
                        скачивайте его только на доверенные устройства.
                    </template>
                    <template v-if="offsite">
                        Копии дублируются на диск <code>{{ offsite }}</code
                        >.
                    </template>
                    Все действия записываются в журнал. Восстановление из копии
                    —
                    <code>php artisan app:db-restore имя-файла</code>.
                    <template v-if="driver">
                        Драйвер БД: <code>{{ driver }}</code
                        >.
                    </template>
                </p>
                <NButton
                    v-if="can('backups.create')"
                    variant="primary"
                    icon="plus"
                    class="intro__action"
                    :loading="creating || pending"
                    :disabled="pending"
                    @click="createBackup"
                    >{{ pending ? "Создаётся…" : "Создать копию" }}</NButton
                >
            </div>

            <div v-if="pending" class="pending" role="status">
                <NSpinner :size="16" :width="2" />
                Копия создаётся в фоне. Список обновится автоматически.
            </div>
            <NAlert
                v-else-if="lastFailure"
                tone="danger"
                title="Последняя ручная копия не создана"
            >
                {{ lastFailure.message }} ({{ formatDateTime(lastFailure.at) }})
            </NAlert>

            <NCard padding="0">
                <ul v-if="backups.length" class="list">
                    <li v-for="file in backups" :key="file.name" class="row">
                        <span class="row__ico"
                            ><NIcon
                                :name="file.encrypted ? 'lock' : 'layers'"
                                :size="18"
                        /></span>
                        <div class="row__main">
                            <span class="row__head">
                                <span class="row__name">{{ file.name }}</span>
                                <NBadge
                                    size="sm"
                                    pill
                                    :tone="
                                        file.kind === 'manual'
                                            ? 'accent'
                                            : 'neutral'
                                    "
                                    >{{
                                        kindLabels[file.kind] ?? file.kind
                                    }}</NBadge
                                >
                                <NBadge
                                    v-if="file.encrypted"
                                    size="sm"
                                    pill
                                    tone="ok"
                                    >зашифрована</NBadge
                                >
                            </span>
                            <span class="row__meta">
                                {{ formatDateTime(file.created_at) }} ·
                                {{ formatRelative(file.created_at) }} ·
                                {{ formatBytes(file.size) }}
                            </span>
                        </div>
                        <div class="row__actions">
                            <NButton
                                v-if="can('backups.download')"
                                variant="secondary"
                                size="sm"
                                icon="download"
                                :as="'a'"
                                :href="`/admin/backups/${encodeURIComponent(file.name)}`"
                                :aria-label="`Скачать ${file.name}`"
                                >Скачать</NButton
                            >
                            <NButton
                                v-if="can('backups.delete')"
                                variant="ghost"
                                tone="danger"
                                size="sm"
                                icon="trash"
                                :aria-label="`Удалить ${file.name}`"
                                @click="del.ask(file)"
                            />
                        </div>
                    </li>
                </ul>
                <NEmptyState
                    v-else
                    icon="shield"
                    title="Копий пока нет"
                    :description="
                        can('backups.create')
                            ? 'Создайте первую копию кнопкой выше или настройте запуск app:db-backup по расписанию.'
                            : 'Резервные копии появятся здесь после запуска app:db-backup.'
                    "
                />
            </NCard>
        </div>

        <ConfirmModal
            :open="del.open"
            :loading="del.loading"
            title="Удалить резервную копию?"
            :message="`Файл «${del.payload?.name}» будет удалён с сервера без возможности восстановления.${offsite ? ' Копия на внешнем диске останется.' : ''}`"
            @confirm="confirmDelete"
            @cancel="del.close"
            @update:open="del.open = $event"
        />
    </AdminLayout>
</template>

<style scoped>
.intro {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 16px;
    border-radius: var(--radius-lg);
    background: var(--accent-soft);
    border: 1px solid var(--border);
    flex-wrap: wrap;
}
.intro__ico {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: var(--accent);
    color: #fff;
    flex: none;
}
.intro__text {
    flex: 1 1 320px;
    margin: 0;
    font-size: 13.5px;
    line-height: 1.5;
    color: var(--text-2);
}
.intro__text code {
    font-family: var(--font-mono);
    font-size: 12.5px;
    color: var(--accent);
}
.intro__action {
    flex: none;
}
.pending {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--text-2);
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
    color: var(--text-3);
    flex: none;
}
.row__main {
    display: flex;
    flex-direction: column;
    gap: 2px;
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
</style>
