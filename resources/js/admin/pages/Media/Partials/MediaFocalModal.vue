<script setup lang="ts">
import { ref, useId, watch } from "vue";
import type { PropType } from "vue";
import { router } from "@inertiajs/vue3";
import { NButton, NModal } from "nergous-ui-vue";
import type { MediaDetails } from "@/admin/types";
import { percent, useFocalPoint } from "@/admin/composables/useMediaCrop";
import { fileName } from "@/lib/media";

const props = defineProps({
    media: { type: Object as PropType<MediaDetails>, required: true },
});
const emit = defineEmits<{
    saved: [
        id: number,
        patch: { focal_x: number | null; focal_y: number | null },
    ];
}>();
const open = defineModel<boolean>({ default: false });

const hintId = useId();
// Focal point: cards and pickers keep that spot in view when they crop.
const { stage: focalStage, point: focalPoint, pick, onKey } = useFocalPoint();
const saving = ref(false);
const announcement = ref("");

watch(open, (value) => {
    if (!value) return;
    const d = props.media;
    focalPoint.value =
        d.focal_x != null && d.focal_y != null
            ? { x: d.focal_x, y: d.focal_y }
            : null;
    announcement.value = "";
});

function onKeydown(e: KeyboardEvent) {
    if (!onKey(e) || !focalPoint.value) return;
    announcement.value = `Точка фокуса: ${percent(focalPoint.value.x)} по горизонтали, ${percent(focalPoint.value.y)} по вертикали`;
}

/** "По центру": no focal point, cards crop around the middle. */
function clearPoint() {
    focalPoint.value = null;
}

function save() {
    const target = props.media;
    const point = focalPoint.value;
    const patch = {
        focal_x: point ? Number(point.x.toFixed(4)) : null,
        focal_y: point ? Number(point.y.toFixed(4)) : null,
    };
    saving.value = true;
    router.patch(`/admin/media/${target.id}`, patch, {
        preserveScroll: true,
        onSuccess: () => {
            emit("saved", target.id, patch);
            open.value = false;
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}
</script>

<template>
    <NModal v-model="open" title="Точка фокуса" width="640px">
        <div class="imgedit">
            <p :id="hintId" class="imgedit__hint">
                Нажмите на главное в кадре или переместите точку стрелками (с
                Shift шаг больше). При обрезке карточками и превью эта точка
                останется видимой.
            </p>
            <div
                ref="focalStage"
                class="imgstage imgstage--focal"
                tabindex="0"
                role="group"
                aria-label="Точка фокуса"
                :aria-describedby="hintId"
                @click="pick"
                @keydown="onKeydown"
            >
                <img
                    :src="media.url"
                    :alt="media.alt || fileName(media)"
                    draggable="false"
                />
                <span
                    v-if="focalPoint"
                    class="focal-dot"
                    :style="{
                        left: focalPoint.x * 100 + '%',
                        top: focalPoint.y * 100 + '%',
                    }"
                />
            </div>
            <p class="sr-only" aria-live="polite">{{ announcement }}</p>
        </div>
        <template #footer="{ close }">
            <NButton
                variant="ghost"
                block
                :disabled="!focalPoint"
                @click="clearPoint"
                >По центру</NButton
            >
            <NButton variant="secondary" block @click="close">Отмена</NButton>
            <NButton
                variant="primary"
                block
                :loading="saving"
                data-enter-submit
                @click="save"
                >Сохранить</NButton
            >
        </template>
    </NModal>
</template>

<style scoped src="./imgedit.css"></style>
<style scoped>
.imgstage--focal {
    cursor: pointer;
}
.imgstage--focal:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.focal-dot {
    position: absolute;
    width: 22px;
    height: 22px;
    margin: -11px 0 0 -11px;
    border-radius: 50%;
    border: 3px solid #fff;
    background: var(--accent);
    box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.45);
    pointer-events: none;
}
</style>
