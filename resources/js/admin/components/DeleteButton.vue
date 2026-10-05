<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { NButton, NConfirmDialog } from "nergous-ui-vue";

// "Delete" button with a confirmation dialog for edit and detail pages.
// Extra attributes (class, size) go to the button.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    url: { type: String, required: true },
    message: { type: String, required: true },
    label: { type: String, default: "Удалить" },
    confirmLabel: { type: String, default: "Удалить" },
});

const open = ref(false);
const loading = ref(false);

function confirm() {
    loading.value = true;
    router.delete(props.url, {
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
    <NConfirmDialog
        v-model="open"
        :loading="loading"
        :message="message"
        :confirm-label="confirmLabel"
        @confirm="confirm"
        danger
    />
</template>
