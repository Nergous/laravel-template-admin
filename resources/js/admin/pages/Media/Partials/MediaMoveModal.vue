<script setup lang="ts">
import type { PropType } from "vue";
import { NButton, NFormField, NInput, NModal } from "nergous-ui-vue";
import FolderChips from "./FolderChips.vue";

defineProps({
    folderNames: { type: Array as PropType<string[]>, default: () => [] },
    error: { type: String, default: "" },
    loading: { type: Boolean, default: false },
});
const emit = defineEmits<{ confirm: [] }>();

const open = defineModel<boolean>({ default: false });
/** Destination path; empty — out of any folder. */
const folder = defineModel<string>("folder", { required: true });
</script>

<template>
    <NModal v-model="open" title="Переместить в папку" width="420px">
        <NFormField
            label="Папка"
            :error="error"
            hint="Новая папка создаётся по имени, вложенная — через «/», например «Баннеры/2026». Пустое поле убирает файлы из папок."
        >
            <NInput
                v-model="folder"
                :error="!!error"
                placeholder="Без папки"
                @keyup.enter="emit('confirm')"
            />
        </NFormField>
        <FolderChips v-model="folder" :folders="folderNames" />
        <template #footer="{ close }">
            <NButton variant="secondary" block @click="close">Отмена</NButton>
            <NButton
                variant="primary"
                block
                :loading="loading"
                data-enter-submit
                @click="emit('confirm')"
                >Переместить</NButton
            >
        </template>
    </NModal>
</template>
