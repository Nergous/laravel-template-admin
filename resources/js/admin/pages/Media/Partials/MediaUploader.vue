<script setup lang="ts">
import { computed } from "vue";
import type { PropType } from "vue";
import { NAlert, NCard, NDropzone, NProgress, NSpinner } from "nergous-ui-vue";
import type { MediaItem } from "@/admin/types";
import {
    acceptAttr,
    useMediaUpload,
    type UploadRules,
} from "@/admin/composables/useMediaUpload";
import { formatBytes } from "@/lib/format";

const props = defineProps({
    uploadRules: { type: Object as PropType<UploadRules>, required: true },
    /** The open folder ("" — the library root): uploads go into it. */
    folder: { type: String, default: "" },
});
const emit = defineEmits<{ items: [items: MediaItem[]] }>();

const { uploading, phase, pct, processingLabel, precheckErrors, upload } =
    useMediaUpload({
        rules: () => props.uploadRules,
        folder: () => props.folder,
        onItems: (items) => emit("items", items),
    });

const accept = computed(() => acceptAttr(props.uploadRules));
const dropHint = computed(
    () =>
        `изображения, видео, аудио, документы · до ${formatBytes(props.uploadRules.maxSizeKb * 1024)} · не более ${props.uploadRules.maxFiles} за раз`,
);
</script>

<template>
    <NCard>
        <NDropzone
            :title="
                folder
                    ? `Перетащите файлы в папку «${folder}»`
                    : 'Перетащите файлы сюда'
            "
            or-label="или"
            browse-label="выберите на устройстве"
            :accept="accept"
            :hint="dropHint"
            @files="upload"
        />
        <NAlert
            v-if="precheckErrors.length"
            class="upload-errors"
            tone="danger"
            title="Файлы не загружены"
        >
            <ul class="upload-errors__list">
                <li v-for="(err, i) in precheckErrors" :key="i">
                    {{ err }}
                </li>
            </ul>
        </NAlert>
        <div v-if="uploading" class="upload-progress">
            <NProgress
                v-if="phase === 'uploading'"
                :value="pct"
                label="Загрузка"
                show-value
            />
            <div
                v-else
                class="upload-processing"
                role="status"
                aria-live="polite"
            >
                <NSpinner :size="18" :width="2" />
                <span class="upload-processing__label">{{
                    processingLabel
                }}</span>
            </div>
        </div>
    </NCard>
</template>

<style scoped>
.upload-progress {
    margin-top: 16px;
}
.upload-errors {
    margin-top: 16px;
}
.upload-errors__list {
    margin: 0;
    padding-left: 18px;
}
/* "Processing…" phase — an indeterminate status with a spinner (NProgress
   only does a determinate bar, so we show a spinner + label). */
.upload-processing {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-2);
    font-size: 12.5px;
    font-weight: 600;
}
.upload-processing__label {
    color: var(--text-2);
}
</style>
