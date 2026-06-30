<script setup>
// BotMessages/Index — bot messages: list of codes from the registry + editing in a drawer.
// Code and default come from the server (messages.json registry); we only edit text/is_active.
import { ref, computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import {
    NDataTable,
    NButton,
    NBadge,
    NAlert,
    NRichText,
    NSwitch,
    NDrawer,
    NFormField,
    NEmptyState,
    NIcon,
} from "@/lib/nergous-cit";
import ConfirmModal from "@/admin/components/ConfirmModal.vue";
import DrawerFooter from "@/admin/components/DrawerFooter.vue";
import MediaPicker from "@/admin/components/MediaPicker.vue";
import { useConfirm } from "@/admin/composables/useConfirm.js";
import { can } from "@/lib/can.js";
import { pluralize } from "@/lib/format.js";

const props = defineProps({
    // [{ code, label, description, default, text, is_overridden, is_active }]
    messages: { type: Array, default: () => [] },
});

const subtitle = computed(
    () =>
        `${props.messages.length} ${pluralize(props.messages.length, "сообщение", "сообщения", "сообщений")}`,
);

// Text is stored as HTML (NRichText). In the table we show a plain view —
// strip tags, collapse whitespace. The full text is visible in the drawer when editing.
function plain(html) {
    if (!html) return "";
    if (typeof document === "undefined") return html;
    const el = document.createElement("div");
    el.innerHTML = html;
    return (el.textContent || "").replace(/\s+/g, " ").trim();
}

// NRichText toolbar labels in Russian. The toolbar is limited to inline formatting
// that MAX understands (format=html): bold/italic/strikethrough/code/link.
const rteTools = ["bold", "italic", "strike", "code", "link"];
const rteLabels = {
    toolbar: "Форматирование",
    bold: "Жирный (Ctrl+B)",
    italic: "Курсив (Ctrl+I)",
    strike: "Зачёркнутый",
    code: "Моноширинный код",
    link: "Ссылка",
    linkPrompt: "URL ссылки",
    linkTitle: "Вставить ссылку",
    linkConfirm: "Применить",
    linkCancel: "Отмена",
    linkRemove: "Удалить ссылку",
};

/* ---------- table ---------- */
const columns = [
    { key: "message", label: "Сообщение" },
    { key: "text", label: "Текст" },
    { key: "status", label: "Статус", width: "150px" },
    { key: "actions", label: "Действия", width: "90px", align: "center" },
];

/* ---------- drawer (edit) ---------- */
const editOpen = ref(false);
const current = ref(null);
const form = useForm({ text: "", is_active: true, media_ids: [] });

// Attached media objects (for previews); form.media_ids is the id list we send.
const attachments = ref([]);
const pickerOpen = ref(false);
const maxAttachments = 10;

function openEdit(message) {
    current.value = message;
    attachments.value = [...(message.attachments ?? [])];
    form.defaults({
        text: message.text,
        is_active: message.is_active,
        media_ids: attachments.value.map((m) => m.id),
    });
    form.reset();
    form.clearErrors();
    editOpen.value = true;
}

// Keep form.media_ids in lockstep with the previewed attachments.
function syncMediaIds() {
    form.media_ids = attachments.value.map((m) => m.id);
}
function onPicked(media) {
    attachments.value = media;
    syncMediaIds();
}
function removeAttachment(id) {
    attachments.value = attachments.value.filter((m) => m.id !== id);
    syncMediaIds();
}

function submitEdit() {
    if (!current.value) return;
    form.put(`/admin/bot-messages/${current.value.code}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

function useDefault() {
    if (current.value) form.text = current.value.default;
}

// Icon shown for a non-image attachment chip (mirrors Media/Index mapping).
const ATT_ICON = {
    image: "asset",
    video: "layers",
    audio: "activity",
    document: "copy",
    other: "asset",
};
function attIcon(m) {
    return ATT_ICON[m.type] || ATT_ICON.other;
}

/* ---------- reset override ---------- */
const reset = useConfirm();
function confirmReset() {
    reset.loading = true;
    router.delete(`/admin/bot-messages/${reset.payload.code}`, {
        preserveScroll: true,
        onFinish: () => reset.close(),
    });
}
</script>

<template>
    <AdminLayout title="Сообщения бота" :subtitle="subtitle">
        <div class="page">
            <NAlert tone="info" title="Тексты бота.">
                Виды сообщений заданы в коде. Здесь можно переопределить текст;
                выключенное переопределение — бот шлёт значение по умолчанию.
            </NAlert>

            <NDataTable
                :columns="columns"
                :rows="messages"
                row-key="code"
                :page-size="0"
                :hover="false"
            >
                <!-- Message: label + code + description -->
                <template #cell-message="{ row }">
                    <div class="mcell">
                        <div class="mcell__top">
                            <span class="mcell__label">{{ row.label }}</span>
                            <code class="mcell__code">{{ row.code }}</code>
                            <span
                                v-if="row.attachments?.length"
                                class="mcell__att"
                                :title="`Вложений: ${row.attachments.length}`"
                            >
                                <NIcon name="asset" :size="13" />
                                {{ row.attachments.length }}
                            </span>
                        </div>
                        <div class="mcell__desc">{{ row.description }}</div>
                    </div>
                </template>

                <!-- Text: current effective value (single line, full text in title) -->
                <template #cell-text="{ row }">
                    <span class="text-cell" :title="plain(row.text)">{{
                        plain(row.text)
                    }}</span>
                </template>

                <!-- Status -->
                <template #cell-status="{ row }">
                    <span class="status-cell">
                        <NBadge
                            :tone="row.is_overridden ? 'accent' : 'neutral'"
                            pill
                            >{{
                                row.is_overridden ? "Изменено" : "По умолчанию"
                            }}</NBadge
                        >
                        <NBadge
                            v-if="row.is_overridden && !row.is_active"
                            tone="warn"
                            pill
                            >Выключено</NBadge
                        >
                    </span>
                </template>

                <!-- Actions -->
                <template #cell-actions="{ row }">
                    <div
                        v-if="can('bot-messages.edit')"
                        class="row-actions row-actions--center"
                    >
                        <NButton
                            variant="ghost"
                            tone="accent"
                            icon="edit"
                            size="sm"
                            class="row-actions__btn"
                            aria-label="Изменить"
                            @click="openEdit(row)"
                        />
                        <NButton
                            v-if="row.is_overridden"
                            variant="ghost"
                            tone="danger"
                            icon="eraser"
                            size="sm"
                            class="row-actions__btn"
                            aria-label="Сбросить к значению по умолчанию"
                            @click="reset.ask(row)"
                        />
                    </div>
                </template>

                <template #empty>
                    <NEmptyState
                        icon="mail"
                        title="Сообщений нет"
                        description="Реестр messages.json пуст."
                    />
                </template>
            </NDataTable>
        </div>

        <NDrawer
            v-model="editOpen"
            :title="current?.label ?? 'Сообщение'"
            :subtitle="current?.code"
            close-label="Закрыть"
        >
            <form class="edit-form" @submit.prevent="submitEdit">
                <p v-if="current?.description" class="edit-desc">
                    {{ current.description }}
                </p>
                <NFormField
                    tag="div"
                    label="Текст сообщения"
                    :error="form.errors.text"
                    required
                >
                    <NRichText
                        v-model="form.text"
                        :tools="rteTools"
                        :labels="rteLabels"
                        placeholder="Текст, который отправит бот"
                    />
                </NFormField>
                <NButton
                    variant="ghost"
                    size="sm"
                    type="button"
                    @click="useDefault"
                    >Подставить значение по умолчанию</NButton
                >

                <!-- Attachments: media from the library sent alongside the text. -->
                <NFormField
                    tag="div"
                    label="Вложения"
                    :error="form.errors.media_ids"
                >
                    <div class="att">
                        <ul v-if="attachments.length" class="att__list">
                            <li
                                v-for="m in attachments"
                                :key="m.id"
                                class="att__item"
                            >
                                <span class="att__thumb">
                                    <img
                                        v-if="m.type === 'image' && m.thumb_url"
                                        :src="m.thumb_url"
                                        :alt="m.original_name"
                                        loading="lazy"
                                    />
                                    <NIcon
                                        v-else
                                        :name="attIcon(m)"
                                        :size="20"
                                    />
                                </span>
                                <span
                                    class="att__name"
                                    :title="m.original_name"
                                    >{{ m.original_name }}</span
                                >
                                <NButton
                                    variant="ghost"
                                    tone="danger"
                                    size="sm"
                                    icon="x"
                                    aria-label="Убрать вложение"
                                    @click="removeAttachment(m.id)"
                                />
                            </li>
                        </ul>
                        <p v-else class="att__empty">Файлы не прикреплены.</p>

                        <NButton
                            v-if="can('media.view')"
                            variant="ghost"
                            size="sm"
                            type="button"
                            icon="plus"
                            @click="pickerOpen = true"
                            >Добавить из медиатеки</NButton
                        >
                    </div>
                </NFormField>

                <label class="edit-active">
                    <NSwitch
                        v-model="form.is_active"
                        aria-label="Использовать это переопределение"
                    />
                    <span>Использовать это переопределение</span>
                </label>
            </form>
            <template #footer="{ close }">
                <DrawerFooter
                    :loading="form.processing"
                    @cancel="close"
                    @save="submitEdit"
                />
            </template>
        </NDrawer>

        <ConfirmModal
            :open="reset.open"
            :loading="reset.loading"
            :message="`Сбросить текст «${reset.payload?.label}» к значению по умолчанию?`"
            confirm-label="Сбросить"
            @confirm="confirmReset"
            @cancel="reset.close"
            @update:open="reset.open = $event"
        />

        <MediaPicker
            v-model="pickerOpen"
            :preselected="attachments"
            :max="maxAttachments"
            @select="onPicked"
        />
    </AdminLayout>
</template>

<style scoped>
/* .page / .row-actions* — shared utilities in resources/js/admin/styles.css */

/* message cell: label + code (one line) + description below */
.mcell {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 8px 0;
}
.mcell__top {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
}
.mcell__label {
    font-weight: 700;
    font-size: 13.5px;
    color: var(--text);
}
.mcell__code {
    font-family: var(--font-mono);
    font-size: 12px;
    color: var(--accent);
}
/* attachments count chip next to the code */
.mcell__att {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-3);
    background: var(--surface-3);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 1px 6px;
}
.mcell__desc {
    font-size: 12.5px;
    color: var(--text-3);
}

/* effective text — single line, ellipsis (full text on hover / in drawer) */
.text-cell {
    display: block;
    max-width: 420px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text-2);
    font-size: 13px;
}

.status-cell {
    display: inline-flex;
    gap: 5px;
    flex-wrap: wrap;
}

/* drawer */
.edit-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.edit-desc {
    margin: 0;
    font-size: 13px;
    line-height: 1.5;
    color: var(--text-3);
}
.edit-active {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13.5px;
    color: var(--text-2);
}

/* attachments block in the drawer */
.att {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: flex-start;
}
.att__list {
    list-style: none;
    margin: 0;
    padding: 0;
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.att__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 5px 6px 5px 5px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
}
.att__thumb {
    flex: none;
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--surface-3);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-3);
}
.att__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.att__name {
    flex: 1;
    min-width: 0;
    font-size: 13px;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.att__empty {
    margin: 0;
    font-size: 12.5px;
    color: var(--text-3);
}
</style>
