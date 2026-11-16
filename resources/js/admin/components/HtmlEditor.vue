<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import {
    NAlert,
    NButton,
    NRichText,
    NSegmented,
    NTextarea,
    NConfirmDialog,
} from "nergous-ui-vue";
import MediaPicker from "@/admin/components/MediaPicker.vue";
import { highlightHtml } from "@/admin/highlightHtml";
import type { MediaItem } from "@/admin/types";

/**
 * Rich-text field with a raw HTML mode.
 *
 * The visual mode uses NRichText preset="full": it keeps images, tables,
 * alignment and the other markup App\Support\Html\HtmlSanitizer allows, and takes
 * images and file links from the media library. Only named anchors (<a name>)
 * are lost there, so content with them opens in HTML mode and switching it
 * needs a confirmation. The server sanitizes the saved HTML again.
 *
 * HTML mode colors the markup: the textarea text is transparent and a <pre>
 * with the highlighted copy lies under it, matched in box, wrap and scroll.
 */
const props = defineProps({
    modelValue: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    error: { type: Boolean, default: false },
    rows: { type: Number, default: 14 },
    /** Hide media insertion for short fields (summaries, notes). */
    media: { type: Boolean, default: true },
});
const emit = defineEmits<{ "update:modelValue": [value: string] }>();

const UNSUPPORTED = /<a\b[^>]*\bname\s*=/i;

const lossy = computed(() => UNSUPPORTED.test(props.modelValue ?? ""));
const mode = ref<"visual" | "html">(lossy.value ? "html" : "visual");
const modes = [
    { value: "visual", label: "Визуально" },
    { value: "html", label: "HTML" },
];

const confirmVisual = ref(false);
function onMode(value: string | number) {
    if (value === "visual" && lossy.value) {
        confirmVisual.value = true;
        return;
    }
    mode.value = value === "html" ? "html" : "visual";
}

// Every full-preset tool except images for short fields.
const SHORT_TOOLS = [
    "undo",
    "redo",
    "bold",
    "italic",
    "underline",
    "strike",
    "sub",
    "sup",
    "h2",
    "h3",
    "h4",
    "alignLeft",
    "alignCenter",
    "alignRight",
    "alignJustify",
    "ul",
    "ol",
    "quote",
    "link",
    "table",
    "hr",
    "code",
    "clear",
    "fullscreen",
] as const;
const tools = computed(() => (props.media ? [] : [...SHORT_TOOLS]));

// Editable area grows with the requested rows; long fields may take 70% of the screen.
const height = computed(() => {
    const min = Math.max(120, props.rows * 22);
    return {
        "--n-rte-min-height": min + "px",
        "--n-rte-max-height": "max(" + min + "px, 70vh)",
    };
});

// Toolbar captions that differ from the library's Russian locale (the rest
// comes from createLocale in app.ts): the admin says "медиатека" and "столбец".
const richLabels = {
    code: "Моноширинный текст",
    imagePick: "Выбрать из медиатеки",
    tableRows: "Строк",
    tableCols: "Столбцов",
    colLeft: "Столбец слева",
    colRight: "Столбец справа",
    colDelete: "Удалить столбец",
};

const wrap = ref<HTMLElement | null>(null);

const source = ref<HTMLElement | null>(null);
const code = ref<HTMLElement | null>(null);
// A trailing newline opens an empty line in the textarea but not in <pre>.
const highlighted = computed(() => {
    const value = props.modelValue ?? "";
    return highlightHtml(value) + (value.endsWith("\n") ? " " : "");
});

function sourceArea(): HTMLTextAreaElement | null {
    return source.value?.querySelector("textarea") ?? null;
}
function syncScroll() {
    const area = sourceArea();
    if (!area || !code.value) return;
    code.value.scrollTop = area.scrollTop;
    code.value.scrollLeft = area.scrollLeft;
}
// Border and padding follow density and the error state; the scrollbar
// narrows only the textarea, so the layer gets it as extra right padding.
function syncBox() {
    const area = sourceArea();
    const layer = code.value;
    if (!area || !layer) return;
    const css = getComputedStyle(area);
    const borderX =
        parseFloat(css.borderLeftWidth) + parseFloat(css.borderRightWidth);
    const scrollbar = area.offsetWidth - area.clientWidth - borderX;
    layer.style.borderStyle = "solid";
    layer.style.borderColor = "transparent";
    layer.style.borderWidth = [
        css.borderTopWidth,
        css.borderRightWidth,
        css.borderBottomWidth,
        css.borderLeftWidth,
    ].join(" ");
    layer.style.padding = [
        css.paddingTop,
        parseFloat(css.paddingRight) + Math.max(0, scrollbar) + "px",
        css.paddingBottom,
        css.paddingLeft,
    ].join(" ");
    syncScroll();
}

watch(
    source,
    (el, _old, onCleanup) => {
        const area = el?.querySelector("textarea");
        if (!area) return;
        const observer = new ResizeObserver(syncBox);
        observer.observe(area);
        syncBox();
        onCleanup(() => observer.disconnect());
    },
    { flush: "post" },
);
watch(
    () => [props.modelValue, props.error],
    () => nextTick(syncBox),
);

const picker = ref<"" | "image" | "document">("");
// Pending pick for the visual editor; HTML mode inserts snippets instead.
let resolvePick: ((item: MediaItem | null) => void) | null = null;

function openPicker(kind: "image" | "document"): Promise<MediaItem | null> {
    resolvePick?.(null);
    picker.value = kind;
    return new Promise((resolve) => {
        resolvePick = resolve;
    });
}
function onPickerOpen(open: boolean) {
    if (open) return;
    picker.value = "";
    resolvePick?.(null);
    resolvePick = null;
}

async function pickImage() {
    const item = await openPicker("image");
    return item ? { src: item.url, alt: item.alt || "" } : null;
}
async function pickLink() {
    const item = await openPicker("document");
    return item
        ? { href: item.url, text: item.original_name || item.url }
        : null;
}

function escapeAttr(value: string): string {
    return value
        .replace(/&/g, "&amp;")
        .replace(/"/g, "&quot;")
        .replace(/</g, "&lt;");
}

function insert(snippet: string) {
    const area = wrap.value?.querySelector("textarea");
    const value = props.modelValue ?? "";
    const start = area?.selectionStart ?? value.length;
    const end = area?.selectionEnd ?? value.length;
    emit(
        "update:modelValue",
        value.slice(0, start) + snippet + value.slice(end),
    );
    requestAnimationFrame(() => {
        if (!area) return;
        area.focus();
        area.selectionStart = area.selectionEnd = start + snippet.length;
    });
}

function onPicked(items: MediaItem[]) {
    const item = items[0];
    if (resolvePick) {
        resolvePick(item ?? null);
        resolvePick = null;
        return;
    }
    if (!item) return;
    if (picker.value === "image") {
        const alt = escapeAttr(item.alt || "");
        insert(
            '<p><img src="' + escapeAttr(item.url) + '" alt="' + alt + '"></p>',
        );
    } else {
        const name = (item.original_name || item.url)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;");
        insert('<a href="' + escapeAttr(item.url) + '">' + name + "</a>");
    }
}
</script>

<template>
    <div ref="wrap" class="html-editor" :style="height">
        <div class="html-editor__bar">
            <NSegmented
                :model-value="mode"
                :options="modes"
                aria-label="Режим редактора"
                @update:model-value="onMode"
            />
            <template v-if="mode === 'html' && media">
                <NButton
                    size="sm"
                    variant="ghost"
                    icon="asset"
                    type="button"
                    @click="picker = 'image'"
                    >Изображение</NButton
                >
                <NButton
                    size="sm"
                    variant="ghost"
                    icon="link"
                    type="button"
                    @click="picker = 'document'"
                    >Файл</NButton
                >
            </template>
        </div>

        <NAlert
            v-if="mode === 'html' && lossy"
            tone="info"
            class="html-editor__note"
        >
            В тексте есть якоря (&lt;a name&gt;). Визуальный редактор их не
            сохраняет, поэтому текст открыт в режиме HTML.
        </NAlert>

        <NRichText
            v-if="mode === 'visual'"
            :model-value="modelValue"
            :placeholder="placeholder"
            :error="error"
            :labels="richLabels"
            preset="full"
            :tools="tools"
            :pick-image="media ? pickImage : undefined"
            :pick-link="media ? pickLink : undefined"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <div v-else ref="source" class="html-editor__source">
            <pre
                ref="code"
                class="html-editor__code"
                aria-hidden="true"
                v-html="highlighted"
            ></pre>
            <NTextarea
                :model-value="modelValue"
                :rows="rows"
                :placeholder="placeholder"
                :error="error"
                spellcheck="false"
                @scroll="syncScroll"
                @update:model-value="emit('update:modelValue', String($event))"
            />
        </div>

        <MediaPicker
            :model-value="picker !== ''"
            :max="1"
            :type="picker === 'image' ? 'image' : 'document'"
            :title="
                picker === 'image'
                    ? 'Вставить изображение'
                    : 'Вставить ссылку на файл'
            "
            confirm-label="Вставить"
            @update:model-value="onPickerOpen"
            @select="onPicked"
        />

        <NConfirmDialog
            v-model="confirmVisual"
            title="Переключить в визуальный режим?"
            message="Визуальный редактор удалит из текста якоря (<a name>). Изменения вступят в силу после сохранения."
            confirm-label="Переключить"
            danger
            @confirm="
                confirmVisual = false;
                mode = 'visual';
            "
        />
    </div>
</template>

<style scoped>
.html-editor {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.html-editor__bar {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.html-editor__source {
    position: relative;
}
.html-editor__source :deep(textarea),
.html-editor__code {
    font-family: var(--font-mono);
    font-size: 12.5px;
    line-height: 1.55;
    letter-spacing: normal;
    tab-size: 4;
    white-space: pre-wrap;
    overflow-wrap: break-word;
    word-break: normal;
}
.html-editor__source :deep(textarea) {
    position: relative;
    display: block;
    background: transparent;
    color: transparent;
    caret-color: var(--text);
}
.html-editor__source :deep(textarea::selection) {
    color: transparent;
    background: color-mix(in srgb, var(--accent) 28%, transparent);
}
.html-editor__code {
    --hl-punct: var(--text-3);
    --hl-tag: #116329;
    --hl-attr: #6639ba;
    --hl-value: #0a3069;
    --hl-entity: #0550ae;
    --hl-comment: #6e7781;
    position: absolute;
    inset: 0;
    margin: 0;
    overflow: hidden;
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text);
    pointer-events: none;
}
[data-theme="dark"] .html-editor__code {
    --hl-tag: #7ee787;
    --hl-attr: #d2a8ff;
    --hl-value: #a5d6ff;
    --hl-entity: #79c0ff;
    --hl-comment: #8b949e;
}
.html-editor__code :deep(.hl-p) {
    color: var(--hl-punct);
}
.html-editor__code :deep(.hl-t) {
    color: var(--hl-tag);
}
.html-editor__code :deep(.hl-a) {
    color: var(--hl-attr);
}
.html-editor__code :deep(.hl-v) {
    color: var(--hl-value);
}
.html-editor__code :deep(.hl-e) {
    color: var(--hl-entity);
}
.html-editor__code :deep(.hl-c),
.html-editor__code :deep(.hl-d) {
    color: var(--hl-comment);
}
</style>
