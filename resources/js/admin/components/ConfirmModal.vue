<script setup>
import { NModal, NButton } from "@/lib/nergous-cit";

defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: "Подтвердите действие" },
    message: { type: String, default: "" },
    confirmLabel: { type: String, default: "Удалить" },
    cancelLabel: { type: String, default: "Отмена" },
    danger: { type: Boolean, default: true },
    loading: { type: Boolean, default: false },
});
const emit = defineEmits(["confirm", "cancel", "update:open"]);
function onClose() {
    emit("update:open", false);
    emit("cancel");
}
</script>

<template>
    <NModal
        :model-value="open"
        :title="title"
        width="420px"
        close-label="Закрыть"
        @update:model-value="onClose"
    >
        <p class="confirm__msg">{{ message }}</p>
        <template #footer="{ close }">
            <NButton variant="secondary" block @click="close">
                {{ cancelLabel }}
            </NButton>
            <NButton
                :variant="danger ? 'danger' : 'primary'"
                block
                :loading="loading"
                @click="emit('confirm')"
            >
                {{ confirmLabel }}
            </NButton>
        </template>
    </NModal>
</template>

<style scoped>
.confirm__msg {
    margin: 0;
    color: var(--text-2);
    font-size: 14px;
    line-height: 1.5;
}
</style>
