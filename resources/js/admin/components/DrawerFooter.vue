<script setup>
// DrawerFooter — the standard pair of NDrawer footer buttons (Cancel / Save).
// The 1:2 layout was repeated verbatim across pages with drawer forms — gathered here.
// The close/save logic is defined by the page via @cancel / @save (NDrawer's #footer
// slot exposes { close }, which we pass into the cancel handler).
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
