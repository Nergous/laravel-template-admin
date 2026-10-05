<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import type { PropType } from "vue";
import { router } from "@inertiajs/vue3";
import {
    NAlert,
    NButton,
    NDrawer,
    NFormField,
    NIcon,
    NInput,
    NSpinner,
    useToast,
} from "nergous-ui-vue";
import type { MediaDetails, MediaItem } from "@/admin/types";
import {
    acceptAttr,
    checkFiles,
    fetchPoll,
    nextPollDelay,
    POLL_FIRST_DELAY_MS,
    POLL_WINDOW_MS,
    reportFailures,
    type UploadRules,
} from "@/admin/composables/useMediaUpload";
import { apiFetch, SessionExpiredError } from "@/lib/api";
import { can } from "@/lib/can";
import { formatBytes, formatDateTime } from "@/lib/format";
import {
    absoluteUrl,
    fileName,
    typeIcon,
    typeLabel,
    useCopyUrl,
} from "@/lib/media";
import FolderChips from "./FolderChips.vue";
import MediaCropModal from "./MediaCropModal.vue";
import MediaFocalModal from "./MediaFocalModal.vue";

const props = defineProps({
    folderNames: { type: Array as PropType<string[]>, default: () => [] },
    uploadRules: { type: Object as PropType<UploadRules>, required: true },
});
const emit = defineEmits<{
    /** Fields of a library row changed on the server. */
    patch: [id: number, patch: Partial<MediaItem>];
    /** The stored file changed (replacement, crop): fresh details. */
    replaced: [details: MediaDetails];
}>();

const toast = useToast();
const copyUrl = useCopyUrl();
const accept = computed(() => acceptAttr(props.uploadRules));

// File details drawer (GET /admin/media/{id}: dimensions, uploader, dates).
const infoOpen = ref(false);
const info = ref<MediaDetails | null>(null);
const infoLoading = ref(false);
// The details request of the file the drawer shows. Opening another file
// aborts it, so a late answer can never fill the drawer (and the form that
// saves name, alt and folder) with the previous file.
let infoRequest: AbortController | null = null;

async function fetchDetails(
    id: number,
    signal?: AbortSignal,
): Promise<MediaDetails> {
    const res = await apiFetch(`/admin/media/${id}`, { signal });
    if (!res.ok) throw new Error(String(res.status));
    return (await res.json()) as MediaDetails;
}

function cancelInfoRequest() {
    infoRequest?.abort();
    infoRequest = null;
    infoLoading.value = false;
}

async function open(m: MediaItem) {
    cancelInfoRequest();
    const request = new AbortController();
    infoRequest = request;
    info.value = {
        ...m,
        dimensions: null,
        uploaded_by: null,
        updated_by: null,
        updated_at: null,
        places: [],
    };
    syncMeta(info.value);
    infoOpen.value = true;
    infoLoading.value = true;
    try {
        const details = await fetchDetails(m.id, request.signal);
        if (request !== infoRequest) return;
        // Keep what the user has already typed while the details loaded.
        const edited = metaDirty.value;
        info.value = details;
        if (!edited) syncMeta(details);
    } catch {
        if (request !== infoRequest) return;
        toast.error("Не удалось загрузить сведения о файле");
    } finally {
        if (request === infoRequest) {
            infoRequest = null;
            infoLoading.value = false;
        }
    }
}

// A closed drawer has nothing to show the answer in.
watch(infoOpen, (value) => {
    if (!value) cancelInfoRequest();
});

// Name, alt text and folder are edited inline in the drawer.
const metaName = ref("");
const metaAlt = ref("");
const metaFolder = ref("");
const metaErrors = ref<Record<string, string>>({});
const metaSaving = ref(false);
function syncMeta(d: MediaItem) {
    metaName.value = fileName(d);
    metaAlt.value = d.alt ?? "";
    metaFolder.value = d.folder ?? "";
    metaErrors.value = {};
}
const metaDirty = computed(
    () =>
        !!info.value &&
        (metaName.value.trim() !== fileName(info.value) ||
            metaAlt.value.trim() !== (info.value.alt ?? "") ||
            metaFolder.value.trim() !== (info.value.folder ?? "")),
);
function saveMeta() {
    const target = info.value;
    if (!target) return;
    const name = metaName.value.trim();
    if (!name) {
        metaErrors.value = { original_name: "Введите имя файла" };
        return;
    }
    const alt = metaAlt.value.trim();
    const folder = metaFolder.value.trim();
    metaSaving.value = true;
    router.patch(
        `/admin/media/${target.id}`,
        { original_name: name, alt, folder },
        {
            preserveScroll: true,
            onSuccess: () => {
                const patch = {
                    original_name: name,
                    alt: alt || null,
                    folder: folder || null,
                };
                emit("patch", target.id, patch);
                if (info.value?.id === target.id) {
                    info.value = { ...info.value, ...patch };
                    syncMeta(info.value);
                }
            },
            onError: (errors) => {
                // Errors of a file the drawer no longer shows don't belong here.
                if (info.value?.id === target.id) metaErrors.value = errors;
            },
            onFinish: () => {
                metaSaving.value = false;
            },
        },
    );
}

/** Fresh details of a stored file (replacement or crop). */
function applyDetails(details: MediaDetails) {
    emit("replaced", details);
    if (info.value?.id === details.id) info.value = details;
}

// Replacing the file keeps the record (id, name, attachments); the queue stores
// the new file under a new path, which the poll below waits for.
const replaceInput = ref<HTMLInputElement | null>(null);
const replacing = ref(false);
let replaceTimer: ReturnType<typeof setTimeout> | null = null;

function pickReplacement() {
    replaceInput.value?.click();
}

function onReplaceFile(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = "";
    const target = info.value;
    if (!file || !target) return;

    const errors = checkFiles([file], props.uploadRules, 1);
    if (errors.length) {
        toast.error("Не удалось заменить файл", errors.join(" "));
        return;
    }

    const fd = new FormData();
    fd.append("file", file);
    replacing.value = true;

    apiFetch(`/admin/media/${target.id}/replace`, {
        method: "POST",
        body: fd,
    })
        .then(async (res) => {
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(
                    json.errors?.file?.[0] || json.message || "Ошибка загрузки",
                );
            }
            waitForReplacement(target.id, String(json.batch ?? ""));
        })
        .catch((err: Error) => {
            replacing.value = false;
            if (err instanceof SessionExpiredError) return;
            toast.error("Не удалось заменить файл", err.message);
        });
}

// Waits until the record shows up in the replacement batch (its row now carries
// the batch id) or the job reports a failure, with the same backoff window as uploads.
function waitForReplacement(id: number, batch: string) {
    if (replaceTimer) clearTimeout(replaceTimer);
    if (!batch) {
        replacing.value = false;
        return;
    }
    const deadline = Date.now() + POLL_WINDOW_MS;
    let delay = POLL_FIRST_DELAY_MS;

    const finish = () => {
        replaceTimer = null;
        replacing.value = false;
    };
    const tick = async () => {
        try {
            const poll = await fetchPoll(batch);
            if (poll.items.some((m) => m.id === id)) {
                const details = await fetchDetails(id);
                applyDetails(details);
                finish();
                toast.success("Файл заменён", fileName(details));
                return;
            }
            if (poll.failed.length) {
                finish();
                reportFailures(toast, poll.failed);
                return;
            }
        } catch (err) {
            if (err instanceof SessionExpiredError) {
                finish();
                return;
            }
            // Transient error: retry on the next tick.
        }
        if (Date.now() + delay > deadline) {
            finish();
            toast.info(
                "Файл ещё обрабатывается",
                "Обновите страницу через минуту.",
            );
            return;
        }
        replaceTimer = setTimeout(tick, delay);
        delay = nextPollDelay(delay);
    };
    replaceTimer = setTimeout(tick, delay);
    delay = nextPollDelay(delay);
}

const cropOpen = ref(false);
const focalOpen = ref(false);

function onFocalSaved(
    id: number,
    patch: { focal_x: number | null; focal_y: number | null },
) {
    emit("patch", id, patch);
    if (info.value?.id === id) info.value = { ...info.value, ...patch };
}

onBeforeUnmount(() => {
    cancelInfoRequest();
    if (replaceTimer) clearTimeout(replaceTimer);
});

defineExpose({ open });
</script>

<template>
    <NDrawer
        v-model="infoOpen"
        :title="can('media.edit') ? 'Редактирование файла' : 'Сведения о файле'"
        :subtitle="info ? fileName(info) : ''"
    >
        <div v-if="info" class="info">
            <div class="info__preview">
                <img
                    v-if="info.type === 'image' && info.thumb_url"
                    :src="info.thumb_url"
                    :srcset="info.srcset || undefined"
                    sizes="420px"
                    :alt="info.alt || fileName(info)"
                />
                <NIcon v-else :name="typeIcon(info)" :size="40" />
                <span v-if="infoLoading || replacing" class="info__busy">
                    <NSpinner :size="20" :width="2" />
                </span>
            </div>
            <dl class="info__list">
                <dt>Имя</dt>
                <dd>{{ fileName(info) }}</dd>
                <dt>Тип</dt>
                <dd>{{ typeLabel(info) }} · {{ info.mime_type || "—" }}</dd>
                <dt>Размер</dt>
                <dd>{{ formatBytes(info.size) }}</dd>
                <template v-if="info.dimensions">
                    <dt>Разрешение</dt>
                    <dd>
                        {{ info.dimensions.width }} ×
                        {{ info.dimensions.height }} px
                    </dd>
                </template>
                <dt>Загрузил</dt>
                <dd>{{ info.uploaded_by || "—" }}</dd>
                <dt>Загружен</dt>
                <dd>
                    {{ info.created_local || formatDateTime(info.created_at) }}
                </dd>
                <template v-if="info.updated_by">
                    <dt>Изменил</dt>
                    <dd>
                        {{ info.updated_by }} ·
                        {{ formatDateTime(info.updated_at) }}
                    </dd>
                </template>
                <dt>Ссылка</dt>
                <dd class="info__url">{{ absoluteUrl(info.url) }}</dd>
                <template v-if="!can('media.edit')">
                    <dt>Папка</dt>
                    <dd>{{ info.folder || "—" }}</dd>
                    <dt>Альтернативный текст</dt>
                    <dd>{{ info.alt || "—" }}</dd>
                </template>
            </dl>
            <NAlert
                v-if="info.places?.length || info.usages?.length"
                tone="info"
                title="Где используется"
            >
                <ul v-if="info.places?.length" class="info__places">
                    <li v-for="(place, index) in info.places" :key="index">
                        {{ place.label }}
                        <template v-if="place.title">
                            —
                            <a v-if="place.url" :href="place.url">{{
                                place.title
                            }}</a>
                            <template v-else>{{ place.title }}</template>
                        </template>
                        <a v-else-if="place.url" :href="place.url">— открыть</a>
                    </li>
                </ul>
                <template v-else>{{ info.usages?.join(", ") }}.</template>
                Пока файл подключён, удалить его нельзя.
            </NAlert>
            <div v-if="can('media.edit')" class="info__meta">
                <NFormField
                    label="Имя файла"
                    :error="metaErrors.original_name"
                    hint="Меняется только отображаемое имя, ссылки на файл не изменятся."
                    required
                >
                    <NInput
                        v-model="metaName"
                        :error="!!metaErrors.original_name"
                        placeholder="Название файла"
                    />
                </NFormField>
                <NFormField
                    label="Альтернативный текст"
                    :error="metaErrors.alt"
                    hint="Описание изображения для экранных дикторов и поисковиков."
                >
                    <NInput
                        v-model="metaAlt"
                        :error="!!metaErrors.alt"
                        placeholder="Что изображено"
                    />
                </NFormField>
                <NFormField
                    label="Папка"
                    :error="metaErrors.folder"
                    hint="Вложенная папка — через «/», например «Баннеры/2026». Оставьте пустым, чтобы убрать файл из папки."
                >
                    <NInput
                        v-model="metaFolder"
                        :error="!!metaErrors.folder"
                        placeholder="Без папки"
                    />
                </NFormField>
                <FolderChips v-model="metaFolder" :folders="folderNames" />
                <div>
                    <NButton
                        variant="primary"
                        size="sm"
                        :disabled="!metaDirty"
                        :loading="metaSaving"
                        data-enter-submit
                        @click="saveMeta"
                        >Сохранить</NButton
                    >
                </div>
            </div>
            <div class="info__actions">
                <NButton variant="secondary" icon="link" @click="copyUrl(info)"
                    >Скопировать ссылку</NButton
                >
                <NButton
                    variant="secondary"
                    icon="ext"
                    :as="'a'"
                    :href="info.url"
                    target="_blank"
                    rel="noopener"
                    >Открыть</NButton
                >
                <NButton
                    v-if="can('media.edit')"
                    variant="secondary"
                    icon="upload"
                    :loading="replacing"
                    @click="pickReplacement"
                    >Заменить файл</NButton
                >
                <template
                    v-if="
                        can('media.edit') &&
                        info.type === 'image' &&
                        info.dimensions
                    "
                >
                    <NButton
                        variant="secondary"
                        icon="grid"
                        :disabled="replacing"
                        @click="cropOpen = true"
                        >Обрезать</NButton
                    >
                    <NButton
                        variant="secondary"
                        icon="eye"
                        :disabled="replacing"
                        @click="focalOpen = true"
                        >Точка фокуса</NButton
                    >
                </template>
            </div>
            <p v-if="can('media.edit')" class="info__hint">
                При замене и обрезке запись и имя сохраняются, а ссылки на файл
                в настройках и других местах, где он выбран, обновляются
                автоматически. Внешние ссылки на старый адрес перестанут
                работать.
            </p>
            <input
                ref="replaceInput"
                type="file"
                class="info__file"
                :accept="accept"
                tabindex="-1"
                aria-hidden="true"
                @change="onReplaceFile"
            />
        </div>
    </NDrawer>

    <template v-if="info">
        <MediaCropModal
            v-model="cropOpen"
            :media="info"
            @saved="applyDetails"
        />
        <MediaFocalModal
            v-model="focalOpen"
            :media="info"
            @saved="onFocalSaved"
        />
    </template>
</template>

<style scoped>
.info {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.info__preview {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    aspect-ratio: 16 / 10;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    background: var(--surface-3);
    color: var(--text-3);
    overflow: hidden;
}
.info__preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.info__busy {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: color-mix(in srgb, var(--surface) 60%, transparent);
}
.info__list {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 8px 16px;
    margin: 0;
    font-size: 13.5px;
}
.info__list dt {
    color: var(--text-3);
}
.info__list dd {
    margin: 0;
    color: var(--text);
    min-width: 0;
    overflow-wrap: anywhere;
}
.info__url {
    font-family: var(--font-mono);
    font-size: 12px;
}
.info__meta {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.info__actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.info__hint {
    margin: 0;
    font-size: 12.5px;
    color: var(--text-3);
}
.info__places {
    margin: 4px 0 6px;
    padding-left: 18px;
}
.info__file {
    display: none;
}
</style>
