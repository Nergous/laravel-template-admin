<script setup lang="ts">
import { computed, ref, watch } from "vue";
import type { PropType } from "vue";
import { NButton, NCheckbox, NSegmented, NPopover } from "nergous-ui-vue";

interface ExportColumn {
    key: string;
    label: string;
}

// Export menu for a list: file format and the columns to include. The choice
// is remembered in this browser under storageKey. The download link adds
// format and columns to url, which already carries the list filters.
const props = defineProps({
    url: { type: String, required: true },
    columns: { type: Array as PropType<ExportColumn[]>, required: true },
    storageKey: { type: String, required: true },
    disabled: { type: Boolean, default: false },
});

const FORMATS = [
    { value: "xlsx", label: "Excel (XLSX)" },
    { value: "csv", label: "CSV" },
];

type Saved = { format?: string; hidden?: string[] };
function read(): Saved {
    try {
        const saved = JSON.parse(
            localStorage.getItem(props.storageKey) ?? "{}",
        );
        return saved && typeof saved === "object" ? saved : {};
    } catch {
        return {};
    }
}

const saved = read();
const format = ref(
    FORMATS.some((f) => f.value === saved.format) ? saved.format! : "xlsx",
);
const hidden = ref<string[]>(
    Array.isArray(saved.hidden)
        ? saved.hidden.filter((key) => props.columns.some((c) => c.key === key))
        : [],
);
watch([format, hidden], () => {
    try {
        localStorage.setItem(
            props.storageKey,
            JSON.stringify({ format: format.value, hidden: hidden.value }),
        );
    } catch {
        // Storage unavailable: the choice lasts until the page is reloaded.
    }
});

const chosen = computed(() =>
    props.columns.filter((c) => !hidden.value.includes(c.key)),
);
const allChosen = computed(() => hidden.value.length === 0);

function toggle(key: string, shown: boolean) {
    hidden.value = shown
        ? hidden.value.filter((k) => k !== key)
        : [...hidden.value, key];
}

function toggleAll(shown: boolean) {
    hidden.value = shown ? [] : props.columns.map((c) => c.key);
}

const href = computed(() => {
    const params = new URLSearchParams({
        format: format.value,
        columns: chosen.value.map((c) => c.key).join(","),
    });
    return props.url + (props.url.includes("?") ? "&" : "?") + params;
});
</script>

<template>
    <NPopover label="Экспорт" :disabled="disabled" width="260px">
        <template #default="{ close }">
            <div class="export">
                <div class="export__section">
                    <span class="export__title">Формат</span>
                    <NSegmented v-model="format" :options="FORMATS" />
                </div>
                <div class="export__section">
                    <span class="export__title">Столбцы</span>
                    <NCheckbox
                        :model-value="allChosen"
                        :indeterminate="!allChosen && chosen.length > 0"
                        @update:model-value="toggleAll"
                        >Все</NCheckbox
                    >
                    <div class="export__columns">
                        <NCheckbox
                            v-for="c in columns"
                            :key="c.key"
                            :model-value="!hidden.includes(c.key)"
                            @update:model-value="
                                (v: boolean) => toggle(c.key, v)
                            "
                            >{{ c.label }}</NCheckbox
                        >
                    </div>
                </div>
                <NButton
                    :as="'a'"
                    :href="href"
                    variant="primary"
                    icon="download"
                    :disabled="chosen.length === 0"
                    @click="close()"
                    >Скачать</NButton
                >
            </div>
        </template>
    </NPopover>
</template>

<style scoped>
.export {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.export__section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.export__title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: var(--text-3);
}
.export__columns {
    display: grid;
    gap: 8px;
    padding: 10px 0 0 4px;
    border-top: 1px solid var(--border);
}
</style>
