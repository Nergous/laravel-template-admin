<script setup lang="ts">
import type { PropType } from "vue";
import { NInput, NSegmented, NSelect } from "nergous-ui-vue";
import type { SelectOption } from "@/admin/types";

defineProps({
    /** The open folder; "" — the library root (search spans the library). */
    currentFolder: { type: String, default: "" },
    filterOpts: { type: Array as PropType<SelectOption[]>, required: true },
});
const emit = defineEmits<{
    /** The search text changed (the page debounces the reload). */
    search: [];
    /** A filter or the sort changed: reload from the first page. */
    change: [];
}>();

const search = defineModel<string>("search", { required: true });
const type = defineModel<string>("type", { required: true });
const usage = defineModel<string>("usage", { required: true });
const sort = defineModel<string>("sort", { required: true });
const view = defineModel<string>("view", { required: true });

const usageOpts = [
    { value: "", label: "Все файлы" },
    { value: "used", label: "Используются" },
    { value: "unused", label: "Не используются" },
    { value: "no_alt", label: "Без альтернативного текста" },
];
const sortOpts = [
    { value: "created_at:desc", label: "Сначала новые" },
    { value: "created_at:asc", label: "Сначала старые" },
    { value: "original_name:asc", label: "По имени, А → Я" },
    { value: "original_name:desc", label: "По имени, Я → А" },
    { value: "size:desc", label: "Сначала большие" },
    { value: "size:asc", label: "Сначала маленькие" },
    { value: "mime_type:asc", label: "По типу, А → Я" },
    { value: "mime_type:desc", label: "По типу, Я → А" },
];
const viewOpts = [
    { value: "grid", icon: "grid", label: "Сетка" },
    { value: "list", icon: "list", label: "Список" },
];
</script>

<template>
    <div class="toolbar">
        <div class="toolbar__group">
            <NInput
                v-model="search"
                class="toolbar__search"
                icon="search"
                :placeholder="
                    currentFolder
                        ? 'Поиск в этой папке…'
                        : 'Поиск по всей медиатеке…'
                "
                aria-label="Поиск по имени файла"
                @update:model-value="emit('search')"
            />
            <NSegmented
                v-model="type"
                :options="filterOpts"
                aria-label="Фильтр по типу"
                @update:model-value="emit('change')"
            />
            <NSelect
                v-model="usage"
                :options="usageOpts"
                aria-label="Использование"
                class="toolbar__usage"
                @update:model-value="emit('change')"
            />
        </div>
        <div class="toolbar__group">
            <NSelect
                v-model="sort"
                :options="sortOpts"
                aria-label="Сортировка"
                class="toolbar__sort"
                @update:model-value="emit('change')"
            />
            <NSegmented v-model="view" :options="viewOpts" aria-label="Вид" />
        </div>
    </div>
</template>

<style scoped>
.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.toolbar__group {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.toolbar__search {
    width: 260px;
    max-width: 100%;
}
:deep(.toolbar__sort) {
    min-width: 190px;
}
:deep(.toolbar__usage) {
    min-width: 170px;
}
</style>
