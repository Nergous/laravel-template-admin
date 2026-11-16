<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { NButton, NConfirmDialog } from "nergous-ui-vue";
import TrashConfirmDialog from "@/admin/components/TrashConfirmDialog.vue";

// "Delete" button with a confirmation dialog for edit and detail pages.
// Extra attributes (class, size) go to the button.
// With forceMessage the dialog offers "Удалить навсегда" (TrashConfirmDialog):
// for records with a trash, the request then carries force=1.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    url: { type: String, required: true },
    message: { type: String, required: true },
    forceMessage: { type: String, default: "" },
    label: { type: String, default: "Удалить" },
    confirmLabel: { type: String, default: "Удалить" },
});

const open = ref(false);
const loading = ref(false);

function confirm(force = false) {
    loading.value = true;
    router.delete(props.url, {
        data: force ? { force: 1 } : undefined,
        onFinish: () => {
            loading.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <NButton
        v-bind="$attrs"
        type="button"
        variant="danger"
        icon="trash"
        @click="open = true"
        >{{ label }}</NButton
    >
    <TrashConfirmDialog
        v-if="forceMessage"
        v-model="open"
        :loading="loading"
        :message="message"
        :force-message="forceMessage"
        @confirm="confirm"
    />
    <NConfirmDialog
        v-else
        v-model="open"
        :loading="loading"
        :message="message"
        :confirm-label="confirmLabel"
        @confirm="confirm()"
        danger
    />
</template>
