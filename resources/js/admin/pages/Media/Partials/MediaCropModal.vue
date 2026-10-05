<script setup lang="ts">
import { computed, ref, useId, watch } from "vue";
import type { PropType } from "vue";
import { NAlert, NButton, NModal, NSegmented, useToast } from "nergous-ui-vue";
import type { MediaDetails } from "@/admin/types";
import {
    CROP_ASPECTS,
    percent,
    useMediaCrop,
} from "@/admin/composables/useMediaCrop";
import { apiFetch, SessionExpiredError } from "@/lib/api";
import { fileName } from "@/lib/media";

const props = defineProps({
    media: { type: Object as PropType<MediaDetails>, required: true },
});
const emit = defineEmits<{ saved: [details: MediaDetails] }>();
const open = defineModel<boolean>({ default: false });

const toast = useToast();
const hintId = useId();

// The area is picked on the preview and sent as fractions (0..1).
const {
    stage: cropStage,
    box: cropBox,
    aspect: cropAspect,
    reset,
    onPointerDown,
    onPointerMove,
    onPointerUp,
    onBoxKey,
    onHandleKey,
} = useMediaCrop(() => {
    const d = props.media.dimensions;
    return d && d.height ? d.width / d.height : 1;
});
const saving = ref(false);
const error = ref("");
// Keyboard changes are read out; pointer drags would flood the announcer.
const announcement = ref("");

watch(open, (value) => {
    if (!value) return;
    reset();
    error.value = "";
    announcement.value = "";
});

const cropPixels = computed(() => {
    const d = props.media.dimensions;
    if (!d) return "";
    return `${Math.round(cropBox.value.width * d.width)} × ${Math.round(cropBox.value.height * d.height)} px`;
});

function announce() {
    const b = cropBox.value;
    announcement.value = `Рамка: ${percent(b.x)} слева, ${percent(b.y)} сверху, размер ${cropPixels.value || `${percent(b.width)} × ${percent(b.height)}`}`;
}
function onBoxKeydown(e: KeyboardEvent) {
    if (onBoxKey(e)) announce();
}
function onHandleKeydown(e: KeyboardEvent) {
    if (onHandleKey(e)) announce();
}

async function save() {
    const target = props.media;
    saving.value = true;
    error.value = "";
    try {
        const res = await apiFetch(`/admin/media/${target.id}/crop`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(cropBox.value),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            const errors = (json.errors ?? {}) as Record<string, string[]>;
            error.value =
                Object.values(errors)[0]?.[0] ||
                json.message ||
                "Не удалось обрезать изображение";
            return;
        }
        emit("saved", json as MediaDetails);
        open.value = false;
        toast.success("Изображение обрезано", fileName(json as MediaDetails));
    } catch (err) {
        if (!(err instanceof SessionExpiredError)) {
            error.value = "Нет связи с сервером";
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <NModal v-model="open" title="Обрезать изображение" width="760px">
        <div class="imgedit">
            <div class="imgedit__bar">
                <NSegmented
                    v-model="cropAspect"
                    :options="CROP_ASPECTS"
                    aria-label="Пропорции"
                />
                <span class="imgedit__size">{{ cropPixels }}</span>
            </div>
            <div
                ref="cropStage"
                class="imgstage imgstage--crop"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
            >
                <img
                    :src="media.url"
                    :alt="media.alt || fileName(media)"
                    draggable="false"
                />
                <div
                    class="cropbox"
                    data-crop="box"
                    tabindex="0"
                    role="group"
                    aria-label="Рамка обрезки"
                    :aria-describedby="hintId"
                    :style="{
                        left: cropBox.x * 100 + '%',
                        top: cropBox.y * 100 + '%',
                        width: cropBox.width * 100 + '%',
                        height: cropBox.height * 100 + '%',
                    }"
                    @keydown="onBoxKeydown"
                >
                    <span
                        class="cropbox__handle"
                        data-crop="handle"
                        tabindex="0"
                        role="group"
                        aria-label="Размер рамки обрезки"
                        :aria-describedby="hintId"
                        @keydown="onHandleKeydown"
                    />
                </div>
            </div>
            <p :id="hintId" class="imgedit__hint">
                Выделите область мышью, перетащите рамку или потяните за угол. С
                клавиатуры стрелки двигают рамку, а на её углу меняют размер; с
                Shift шаг больше. Будет создан новый файл, адрес изображения
                изменится.
            </p>
            <p class="sr-only" aria-live="polite">{{ announcement }}</p>
            <NAlert v-if="error" tone="danger">{{ error }}</NAlert>
        </div>
        <template #footer="{ close }">
            <NButton variant="secondary" block @click="close">Отмена</NButton>
            <NButton
                variant="primary"
                block
                :loading="saving"
                data-enter-submit
                @click="save"
                >Обрезать</NButton
            >
        </template>
    </NModal>
</template>

<style scoped src="./imgedit.css"></style>
<style scoped>
.imgstage--crop {
    cursor: crosshair;
}
.cropbox {
    position: absolute;
    box-sizing: border-box;
    border: 2px solid #fff;
    box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
    cursor: move;
}
.cropbox:focus-visible {
    outline: 2px solid var(--accent);
    /* Inside the white border: the stage clips anything outside the image. */
    outline-offset: -4px;
}
.cropbox__handle {
    position: absolute;
    right: -7px;
    bottom: -7px;
    width: 14px;
    height: 14px;
    border-radius: 3px;
    background: #fff;
    border: 2px solid var(--accent);
    cursor: nwse-resize;
}
.cropbox__handle:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
</style>
