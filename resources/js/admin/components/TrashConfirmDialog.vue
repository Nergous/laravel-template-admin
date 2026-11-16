<script setup lang="ts">
import { computed, watch } from "vue";
import { NCheckbox, NConfirmDialog } from "nergous-ui-vue";

// Delete confirmation for records with a trash (soft-deleted models, e.g. users).
// Moves to the trash by default; the "Удалить навсегда" checkbox switches the
// dialog to permanent deletion: forceMessage, the confirm label, and
// confirm(true). The checkbox is cleared every time the dialog opens.
// v-model controls open state; v-model:force exposes the checkbox if needed.
const props = defineProps({
    message: { type: String, required: true },
    forceMessage: { type: String, required: true },
    title: { type: String, default: undefined },
    forceTitle: { type: String, default: undefined },
    loading: { type: Boolean, default: false },
});
const emit = defineEmits<{ confirm: [force: boolean] }>();

const open = defineModel<boolean>({ default: false });
const force = defineModel<boolean>("force", { default: false });

watch(open, (isOpen) => {
    if (isOpen) force.value = false;
});

const title = computed(() =>
    force.value ? (props.forceTitle ?? props.title) : props.title,
);
</script>

<template>
    <NConfirmDialog
        v-model="open"
        :loading="loading"
        :title="title"
        :message="force ? forceMessage : message"
        :confirm-label="force ? 'Удалить навсегда' : 'В корзину'"
        @confirm="emit('confirm', force)"
        danger
    >
        <NCheckbox v-model="force" :disabled="loading"
            >Удалить навсегда</NCheckbox
        >
    </NConfirmDialog>
</template>
