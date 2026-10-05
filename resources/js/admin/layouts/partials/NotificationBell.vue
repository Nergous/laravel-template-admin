<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { NButton, NCheckbox, NDrawer, NSpinner } from "nergous-ui-vue";
import {
    NOTIFICATION_CATEGORIES,
    useNotifications,
} from "@/admin/composables/useNotifications";

// Topbar bell with its drawer; the layout renders it only for users who may
// read the activity log.
const {
    count,
    open,
    loading,
    saving,
    settingsOpen,
    filter,
    feed,
    visibleItems,
    filterOptions,
    show,
    toggleMute,
} = useNotifications();
</script>

<template>
    <span class="tb-bell">
        <NButton
            variant="secondary"
            :aria-label="count ? `Уведомления, новых: ${count}` : 'Уведомления'"
            icon="bell"
            @click="show"
        />
        <span v-if="count" class="tb-bell__badge" aria-hidden="true">{{
            count > 9 ? "9+" : count
        }}</span>
    </span>
    <NDrawer v-model="open" title="Уведомления">
        <div class="notif-tools">
            <div
                v-if="filterOptions.length > 1"
                class="notif-chips"
                role="group"
                aria-label="Тип событий"
            >
                <button
                    type="button"
                    class="notif-chip"
                    :class="{ 'notif-chip--on': filter === '' }"
                    :aria-pressed="filter === ''"
                    @click="filter = ''"
                >
                    Все
                </button>
                <button
                    v-for="c in filterOptions"
                    :key="c.value"
                    type="button"
                    class="notif-chip"
                    :class="{ 'notif-chip--on': filter === c.value }"
                    :aria-pressed="filter === c.value"
                    @click="filter = c.value"
                >
                    {{ c.label }}
                </button>
            </div>
            <NButton
                size="sm"
                variant="ghost"
                icon="settings"
                class="notif-tools__gear"
                :aria-expanded="settingsOpen"
                @click="settingsOpen = !settingsOpen"
                >Настроить</NButton
            >
        </div>
        <div v-if="settingsOpen" class="notif-settings">
            <div class="notif-settings__title">Показывать события</div>
            <NCheckbox
                v-for="c in NOTIFICATION_CATEGORIES"
                :key="c.value"
                :model-value="!feed.mutes.includes(c.value)"
                :disabled="saving"
                @update:model-value="(v: boolean) => toggleMute(c.value, v)"
                >{{ c.label }}</NCheckbox
            >
        </div>
        <div v-if="loading" style="text-align: center">
            <NSpinner
                :size="18"
                :width="2"
                class="notif-spinner"
                label="Загрузка уведомлений..."
            />
        </div>

        <div v-if="!visibleItems.length && !loading" class="notif-empty">
            Свежих событий нет
        </div>
        <Link
            v-for="it in visibleItems"
            :key="it.id"
            :href="it.url"
            class="notif-item"
            :class="{ 'notif-item--unread': it.unread }"
            @click="open = false"
        >
            <div class="notif-item__top">
                <b>{{ it.user }}</b>
                <span class="notif-item__time">{{ it.time }}</span>
            </div>
            <div class="notif-item__body">
                {{ it.action }} · {{ it.subject }}
                <span v-if="it.repeat > 1" class="notif-item__repeat"
                    >×{{ it.repeat }}</span
                >
            </div>
        </Link>
    </NDrawer>
</template>

<style scoped>
.tb-bell {
    position: relative;
    display: inline-flex;
    flex: none;
}
.tb-bell__badge {
    position: absolute;
    top: -5px;
    right: -5px;
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 4px;
    border-radius: 9px;
    background: var(--danger);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    pointer-events: none;
    border: 2px solid var(--surface);
}
.notif-empty {
    padding: 24px 4px;
    color: var(--text-3);
    text-align: center;
    font-size: 13.5px;
}
.notif-tools {
    display: flex;
    align-items: flex-start;
    gap: var(--sp-2, 8px);
    margin-bottom: 10px;
}
.notif-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    flex: 1;
}
.notif-tools__gear {
    margin-left: auto;
}
.notif-chip {
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-2);
    border-radius: 999px;
    padding: 3px 10px;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
}
.notif-chip--on {
    background: var(--accent-soft);
    border-color: var(--accent);
    color: var(--text);
}
.notif-settings {
    display: grid;
    gap: 8px;
    padding: 12px;
    margin-bottom: 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-2);
}
.notif-settings__title {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.notif-item__repeat {
    margin-left: 6px;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--danger-bg);
    color: var(--danger);
    font-weight: 600;
    font-size: 11.5px;
}
.notif-item {
    display: block;
    padding: 12px;
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--text);
}
.notif-item:hover {
    background: var(--surface-2);
}
.notif-item--unread {
    background: var(--accent-soft);
}
.notif-item__top {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
}
.notif-item__time {
    color: var(--text-3);
}
.notif-item__body {
    font-size: 12.5px;
    color: var(--text-2);
    margin-top: 2px;
}
</style>
