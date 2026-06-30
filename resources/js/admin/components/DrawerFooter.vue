<script setup>
// DrawerFooter — стандартная пара кнопок футера NDrawer (Отмена / Сохранить).
// Раскладка 1:2 повторялась дословно на страницах с drawer-формами — собрали сюда.
// Логику закрытия/сохранения задаёт страница через @cancel / @save (слот #footer
// у NDrawer отдаёт { close }, его прокидываем в обработчик отмены).
import { NButton } from "@/lib/nergous-cit";

defineProps({
    cancelLabel: { type: String, default: "Отмена" },
    saveLabel: { type: String, default: "Сохранить" },
    saveVariant: { type: String, default: "primary" },
    loading: { type: Boolean, default: false },
});
defineEmits(["cancel", "save"]);
</script>

<template>
    <NButton
        variant="ghost"
        class="drawer-foot__cancel"
        @click="$emit('cancel')"
    >
        {{ cancelLabel }}
    </NButton>
    <NButton
        :variant="saveVariant"
        class="drawer-foot__save"
        :loading="loading"
        @click="$emit('save')"
    >
        {{ saveLabel }}
    </NButton>
</template>

<style scoped>
.drawer-foot__cancel {
    flex: 1;
    border: 1px solid var(--border);
}
.drawer-foot__save {
    flex: 2;
}
</style>
