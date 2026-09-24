<script setup lang="ts">
import { ref } from "vue";
import type { PropType } from "vue";
import { router } from "@inertiajs/vue3";
import { NButton, NCard, NEmptyState, NIcon } from "nergous-ui-vue";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import { can } from "@/lib/can";
import { formatBytes, formatDateTime, formatRelative } from "@/lib/format";

interface BackupFile {
    name: string;
    size: number;
    created_at: string;
}

defineProps({
    backups: { type: Array as PropType<BackupFile[]>, default: () => [] },
    driver: { type: String, default: "" },
});

const creating = ref(false);
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
</script>

<template>
    <AdminLayout title="Резервные копии" subtitle="Дампы базы данных">
        <div class="page">
            <div class="intro">
                <span class="intro__ico"
                    ><NIcon name="shield" :size="18"
                /></span>
                <p class="intro__text">
                    Дампы создаёт команда <code>app:db-backup</code> в
                    <code>storage/app/backups</code>; хранятся 7 последних. Файл
                    содержит все учётные записи и настройки — скачивайте его
                    только на доверенные устройства. Создание и скачивание
                    записываются в журнал.
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
                    :loading="creating"
                    @click="createBackup"
                    >Создать копию</NButton
                >
            </div>

            <NCard padding="0">
                <ul v-if="backups.length" class="list">
                    <li v-for="file in backups" :key="file.name" class="row">
                        <span class="row__ico"
                            ><NIcon name="layers" :size="18"
                        /></span>
                        <div class="row__main">
                            <span class="row__name">{{ file.name }}</span>
                            <span class="row__meta">
                                {{ formatDateTime(file.created_at) }} ·
                                {{ formatRelative(file.created_at) }} ·
                                {{ formatBytes(file.size) }}
                            </span>
                        </div>
                        <NButton
                            variant="secondary"
                            size="sm"
                            icon="download"
                            :as="'a'"
                            :href="`/admin/backups/${encodeURIComponent(file.name)}`"
                            :aria-label="`Скачать ${file.name}`"
                            >Скачать</NButton
                        >
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
</style>
