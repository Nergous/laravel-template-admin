<script setup lang="ts">
import { NButton } from "nergous-ui-vue";
import type { PropType } from "vue";

defineProps({
    cancelLabel: { type: String, default: "Отмена" },
    saveLabel: { type: String, default: "Сохранить" },
    saveVariant: {
        type: String as PropType<"primary" | "secondary" | "danger" | "ghost">,
        default: "primary",
    },
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
