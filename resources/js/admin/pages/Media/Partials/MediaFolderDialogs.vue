<script setup lang="ts">
import { computed, ref } from "vue";
import type { PropType } from "vue";
import { router } from "@inertiajs/vue3";
import {
    NButton,
    NFormField,
    NInput,
    NModal,
    NConfirmDialog,
} from "nergous-ui-vue";
import { pluralize } from "@/lib/format";
import { baseName, parentOf, type FolderEntry } from "@/lib/media";

const props = defineProps({
    /** The open folder; "" — the library root. */
    currentFolder: { type: String, default: "" },
    folders: { type: Array as PropType<FolderEntry[]>, default: () => [] },
});

// Folder management: create inside the open folder, rename or delete a
// folder (the open one, or a folder tile/row). The server answers with a
// redirect that refreshes the folder list.
const newOpen = ref(false);
const newName = ref("");
const newError = ref("");
const target = ref("");
const renOpen = ref(false);
const renName = ref("");
const renError = ref("");
const busy = ref(false);
const clearOpen = ref(false);

function create() {
    newName.value = "";
    newError.value = "";
    newOpen.value = true;
}
function confirmCreate() {
    const name = newName.value.trim();
    if (!name) {
        newError.value = "Введите название папки";
        return;
    }
    busy.value = true;
    router.post(
        "/admin/media/folders",
        // A new folder goes into the open one.
        { parent: props.currentFolder || null, name },
        {
            preserveScroll: true,
            onSuccess: () => {
                newOpen.value = false;
            },
            onError: (errors) => {
                newError.value = errors.name || "Не удалось создать папку";
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function rename(name = props.currentFolder) {
    target.value = name;
    renName.value = baseName(name);
    renError.value = "";
    renOpen.value = true;
}
function confirmRename() {
    const folder = target.value;
    const name = renName.value.trim();
    if (!name) {
        renError.value = "Введите название папки";
        return;
    }
    busy.value = true;
    router.patch(
        "/admin/media/folders",
        // Renaming the open folder keeps it open under the new name.
        { folder, name, open: folder === props.currentFolder },
        {
            preserveScroll: true,
            onSuccess: () => {
                renOpen.value = false;
            },
            onError: (errors) => {
                renError.value =
                    errors.name ||
                    errors.folder ||
                    "Не удалось переименовать папку";
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

const clearMessage = computed(() => {
    const name = target.value;
    const entry = props.folders.find((f) => f.name === name);
    const n = entry?.total ?? 0;
    const parent = parentOf(name);
    const nested = entry?.subfolders
        ? " Вложенные папки удалятся вместе с ней."
        : "";
    if (!n) return `Удалить пустую папку «${baseName(name)}»?${nested}`;
    const where = parent
        ? `в папку «${baseName(parent)}»`
        : "в медиатеку без папки";
    return `Удалить папку «${baseName(name)}»?${nested} ${n} ${pluralize(n, "файл переместится", "файла переместятся", "файлов переместятся")} ${where}.`;
});
function clear(name = props.currentFolder) {
    target.value = name;
    clearOpen.value = true;
}
function confirmClear() {
    busy.value = true;
    router.delete("/admin/media/folders", {
        data: { folder: target.value },
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            clearOpen.value = false;
        },
    });
}

defineExpose({ create, rename, clear });
</script>

<template>
    <NModal v-model="newOpen" title="Создать папку" width="420px">
        <NFormField
            label="Название"
            :error="newError"
            :hint="
                currentFolder
                    ? `Папка появится внутри «${baseName(currentFolder)}».`
                    : ''
            "
            required
        >
            <NInput
                v-model="newName"
                :error="!!newError"
                placeholder="Например, Баннеры"
            />
        </NFormField>
        <template #footer="{ close }">
            <NButton variant="secondary" block @click="close">Отмена</NButton>
            <NButton
                variant="primary"
                block
                :loading="busy"
                data-enter-submit
                @click="confirmCreate"
                >Создать</NButton
            >
        </template>
    </NModal>

    <NModal v-model="renOpen" title="Переименовать папку" width="420px">
        <NFormField
            label="Новое название"
            :error="renError"
            hint="Если папка с таким названием уже есть, файлы объединятся."
            required
        >
            <NInput v-model="renName" :error="!!renError" />
        </NFormField>
        <template #footer="{ close }">
            <NButton variant="secondary" block @click="close">Отмена</NButton>
            <NButton
                variant="primary"
                block
                :loading="busy"
                data-enter-submit
                @click="confirmRename"
                >Сохранить</NButton
            >
        </template>
    </NModal>

    <NConfirmDialog
        v-model="clearOpen"
        title="Удалить папку"
        :message="clearMessage"
        :loading="busy"
        @confirm="confirmClear"
        danger
        confirm-label="Удалить"
    />
</template>
