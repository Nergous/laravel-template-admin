<script setup>
/**
 * NRichText — lightweight WYSIWYG field built on native `contenteditable`.
 * v-model holds an HTML string (e.g. "<p>Hi <b>there</b></p>"). Zero deps.
 *
 * Mirrors the other form fields: `error` paints the invalid state, `disabled`
 * locks it, `placeholder` shows while empty.
 *
 * NOTE: this is a control GROUP (toolbar buttons + editable), not a single
 * control. When pairing with NFormField, pass `tag="div"` — a wrapping <label>
 * redirects clicks to its first labelable child (the first toolbar button),
 * stealing focus from the editor on mouseup. (NFormField with tag!="label" still
 * wires aria-labelledby through the useFormField contract.)
 *
 * Editing relies on `document.execCommand`. It is formally deprecated but is the
 * only turnkey way to format inside a contenteditable (no browser has removed it
 * and no replacement shipped). All of it is funnelled through `exec()` so a
 * future swap stays local.
 *
 * Security: pasted content is sanitized to a small tag whitelist before it is
 * inserted, so no scripts/styles/handlers enter the value. The HTML this field
 * produces must STILL be sanitized again on render — never trust it blindly.
 */
import {
    ref,
    reactive,
    computed,
    watch,
    nextTick,
    onMounted,
    onBeforeUnmount,
} from "vue";
import NIcon from "../primitives/NIcon.vue";
import NModal from "../overlays/NModal.vue";
import NInput from "./NInput.vue";
import NButton from "./NButton.vue";
import { useFormField } from "../../composables/useFormField.js";

const props = defineProps({
    modelValue: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    error: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    // Toolbar text (button titles/aria-labels, toolbar aria-label, link prompt).
    // Neutral English defaults; the app passes localized strings at the call site.
    // Override per key — merged over DEFAULT_LABELS, so a partial object is fine.
    labels: { type: Object, default: () => ({}) },
    // Restrict the toolbar to these tool ids (see TOOLS). Empty = show all.
    // Separators are dropped automatically in the restricted set. Useful when the
    // target sink supports only a subset of formatting (e.g. inline-only).
    tools: {
        type: Array,
        default: () => [],
        validator: (v) =>
            v.every((id) =>
                [
                    "bold",
                    "italic",
                    "strike",
                    "h2",
                    "h3",
                    "ul",
                    "ol",
                    "link",
                    "quote",
                    "code",
                    "clear",
                ].includes(id),
            ),
    },
});
const emit = defineEmits(["update:modelValue"]);

// Surrounding NFormField (if any) supplies invalid/required/described-by/label.
const field = useFormField();
const invalid = computed(() => props.error || !!field?.invalid.value);

// Accessible names for the toolbar, keyed by tool id (see TOOLS). English
// defaults; the call site localizes via the `labels` prop.
const DEFAULT_LABELS = {
    toolbar: "Formatting",
    bold: "Bold (Ctrl+B)",
    italic: "Italic (Ctrl+I)",
    strike: "Strikethrough",
    h2: "Heading 2",
    h3: "Heading 3",
    ul: "Bulleted list",
    ol: "Numbered list",
    link: "Link",
    quote: "Quote",
    code: "Monospace code",
    clear: "Clear formatting",
    linkPrompt: "Link URL",
    // Link modal (replaces the native prompt).
    linkTitle: "Insert link",
    linkConfirm: "Apply",
    linkCancel: "Cancel",
    linkRemove: "Remove link",
};
const labels = computed(() => ({ ...DEFAULT_LABELS, ...props.labels }));

const editable = ref(null);
const isEmpty = ref(true);

// Link modal state. We can't open a focusable overlay without the editor losing
// its selection, so we stash the Range on open and restore it before applying.
const linkModalOpen = ref(false);
const linkUrl = ref("");
const editingLink = ref(false);
const linkBody = ref(null);
let savedRange = null;
// Reflects the formatting at the caret so toolbar buttons can show pressed state.
const active = reactive({
    bold: false,
    italic: false,
    strike: false,
    ul: false,
    ol: false,
    h2: false,
    h3: false,
    quote: false,
});

// Toolbar layout. `sep` renders a divider; `text` buttons (H2/H3) carry a label
// instead of an icon; `key` ties a button to its `active` flag; `id` looks up the
// button's accessible name in `labels`.
const TOOLS = [
    { id: "bold", cmd: "bold", icon: "bold", key: "bold" },
    { id: "italic", cmd: "italic", icon: "italic", key: "italic" },
    {
        id: "strike",
        cmd: "strikeThrough",
        icon: "strikethrough",
        key: "strike",
    },
    "sep",
    { id: "h2", block: "h2", text: "H2", key: "h2" },
    { id: "h3", block: "h3", text: "H3", key: "h3" },
    "sep",
    { id: "ul", cmd: "insertUnorderedList", icon: "list", key: "ul" },
    { id: "ol", cmd: "insertOrderedList", icon: "list-ordered", key: "ol" },
    "sep",
    { id: "link", action: "link", icon: "link" },
    { id: "quote", block: "blockquote", icon: "quote", key: "quote" },
    { id: "code", action: "code", icon: "code" },
    "sep",
    { id: "clear", action: "clear", icon: "eraser" },
];

// Toolbar actually rendered: the full set (with separators) by default, or only
// the requested buttons (separators dropped) when `tools` is provided.
const visibleTools = computed(() =>
    props.tools.length
        ? TOOLS.filter((t) => t !== "sep" && props.tools.includes(t.id))
        : TOOLS,
);

// --- execCommand wrappers (the only deprecated surface, kept in one place) ----

function exec(cmd, value = null) {
    document.execCommand(cmd, false, value);
}

// queryCommandState, but never throws on unsupported commands.
function state(cmd) {
    try {
        return document.execCommand && document.queryCommandState(cmd);
    } catch {
        return false;
    }
}

// Toggle a block format (h2/h3/blockquote): apply it, or revert to <p> if already on.
function toggleBlock(tag) {
    const current = (
        document.queryCommandValue("formatBlock") || ""
    ).toLowerCase();
    exec("formatBlock", current === tag ? "<p>" : `<${tag}>`);
}

// --- link modal ---------------------------------------------------------------

// Stash / restore the editor selection across the focus change the modal causes.
function saveRange() {
    const sel = document.getSelection();
    savedRange = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
}
function restoreRange() {
    if (!savedRange) return;
    const sel = document.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
}

const isSafeUrl = (url) => !/^\s*javascript:/i.test(url);
function escapeAttr(s) {
    return s.replace(
        /[&<>"]/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c],
    );
}

// Open the link modal: remember the selection; if the caret sits in a link, edit
// it (select the whole anchor and prefill its href) instead of making a new one.
function openLinkModal() {
    saveRange();
    const sel = document.getSelection();
    const node = sel && sel.anchorNode;
    const elNode = node && (node.nodeType === 1 ? node : node.parentElement);
    const anchor = elNode && elNode.closest("a");
    if (anchor) {
        const r = document.createRange();
        r.selectNode(anchor);
        savedRange = r;
        editingLink.value = true;
        linkUrl.value = anchor.getAttribute("href") || "";
    } else {
        editingLink.value = false;
        linkUrl.value = "https://";
    }
    linkModalOpen.value = true;
    // Focus the URL field after the modal's focus-trap has run (rAF beats nextTick).
    if (typeof requestAnimationFrame === "function") {
        requestAnimationFrame(() => {
            const input = linkBody.value?.querySelector("input");
            if (input) {
                input.focus();
                input.select();
            }
        });
    }
}

// Re-focus the editor, restore the saved selection, then run the edit. rAF lands
// this after the trap's focus-restore so execCommand sees the right selection.
function withRestoredSelection(fn) {
    const apply = () => {
        editable.value?.focus();
        restoreRange();
        fn();
        sync();
        updateActive();
    };
    if (typeof requestAnimationFrame === "function")
        requestAnimationFrame(apply);
    else nextTick(apply);
}

function confirmLink() {
    const url = linkUrl.value.trim();
    linkModalOpen.value = false;
    withRestoredSelection(() => {
        if (!url || !isSafeUrl(url)) {
            if (editingLink.value) exec("unlink");
            return;
        }
        const sel = document.getSelection();
        if (!editingLink.value && sel && sel.isCollapsed) {
            // No selection: insert the URL itself as a link.
            exec(
                "insertHTML",
                `<a href="${escapeAttr(url)}">${escapeHtml(url)}</a>`,
            );
        } else {
            exec("createLink", url);
        }
    });
}

function removeLink() {
    linkModalOpen.value = false;
    withRestoredSelection(() => exec("unlink"));
}

// Wrap the selected text in <code>. (v1: no toggle-off — clear formatting removes it.)
function wrapCode() {
    const sel = document.getSelection();
    if (!sel || sel.isCollapsed) return;
    exec("insertHTML", `<code>${escapeHtml(sel.toString())}</code>`);
}

function escapeHtml(s) {
    return s.replace(
        /[&<>]/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" })[c],
    );
}

// --- toolbar dispatch ---------------------------------------------------------

function run(tool) {
    if (props.disabled) return;
    // Link opens a modal — its own flow handles focus/selection/sync.
    if (tool.action === "link") {
        openLinkModal();
        return;
    }
    editable.value.focus();
    if (tool.cmd) exec(tool.cmd);
    else if (tool.block) toggleBlock(tool.block);
    else if (tool.action === "code") wrapCode();
    else if (tool.action === "clear") exec("removeFormat");
    sync();
    updateActive();
}

// --- model <-> DOM sync -------------------------------------------------------

// An "empty" contenteditable still holds <br>/<div> noise — treat no text as empty.
function computeEmpty() {
    const el = editable.value;
    return !el || (!el.textContent.trim() && !el.querySelector("img,hr"));
}

// Push the editor's HTML out through v-model (normalised to "" when empty).
function sync() {
    isEmpty.value = computeEmpty();
    emit("update:modelValue", isEmpty.value ? "" : editable.value.innerHTML);
}

// Refresh toolbar pressed-state from the current selection.
function updateActive() {
    const el = editable.value;
    const sel = document.getSelection();
    if (!el || !sel || !el.contains(sel.anchorNode)) return;
    active.bold = state("bold");
    active.italic = state("italic");
    active.strike = state("strikeThrough");
    active.ul = state("insertUnorderedList");
    active.ol = state("insertOrderedList");
    const block = (
        document.queryCommandValue("formatBlock") || ""
    ).toLowerCase();
    active.h2 = block === "h2";
    active.h3 = block === "h3";
    active.quote = block === "blockquote";
}

// --- paste sanitisation -------------------------------------------------------

const ALLOWED = {
    B: [],
    STRONG: [],
    I: [],
    EM: [],
    S: [],
    STRIKE: [],
    U: [],
    H2: [],
    H3: [],
    P: [],
    BR: [],
    SPAN: [],
    UL: [],
    OL: [],
    LI: [],
    BLOCKQUOTE: [],
    CODE: [],
    PRE: [],
    A: ["href"],
};
// Dangerous elements are dropped whole; unknown ones are unwrapped (text kept).
const DROP = new Set([
    "SCRIPT",
    "STYLE",
    "NOSCRIPT",
    "IFRAME",
    "OBJECT",
    "EMBED",
    "TEMPLATE",
    "LINK",
    "META",
    "HEAD",
]);

function sanitizeHtml(html) {
    const doc = new DOMParser().parseFromString(html, "text/html");
    // querySelectorAll is a static snapshot in document order (parents first),
    // so unwrapped children are still visited afterwards.
    doc.body.querySelectorAll("*").forEach((el) => {
        const tag = el.tagName;
        if (DROP.has(tag)) {
            el.remove();
            return;
        }
        const allowed = ALLOWED[tag];
        if (!allowed) {
            el.replaceWith(...el.childNodes); // unwrap, keep text
            return;
        }
        [...el.attributes].forEach((a) => {
            if (!allowed.includes(a.name.toLowerCase()))
                el.removeAttribute(a.name);
        });
        if (tag === "A") {
            const href = el.getAttribute("href") || "";
            if (/^\s*(javascript|data):/i.test(href))
                el.removeAttribute("href");
            else el.setAttribute("rel", "noopener noreferrer");
        }
    });
    return doc.body.innerHTML;
}

function onPaste(e) {
    if (props.disabled) return;
    e.preventDefault();
    const cb = e.clipboardData;
    const html = cb.getData("text/html");
    if (html) exec("insertHTML", sanitizeHtml(html));
    else exec("insertText", cb.getData("text/plain"));
    sync();
}

// --- lifecycle ----------------------------------------------------------------

function onSelectionChange() {
    if (!props.disabled) updateActive();
}

onMounted(() => {
    editable.value.innerHTML = props.modelValue || "";
    isEmpty.value = computeEmpty();
    document.addEventListener("selectionchange", onSelectionChange);
});

onBeforeUnmount(() => {
    document.removeEventListener("selectionchange", onSelectionChange);
});

// Apply external value changes (form reset, edit load) without stomping the
// caret: our own emits make modelValue === innerHTML, so this is a no-op then.
watch(
    () => props.modelValue,
    (val) => {
        const el = editable.value;
        if (el && val !== el.innerHTML) {
            el.innerHTML = val || "";
            isEmpty.value = computeEmpty();
        }
    },
);
</script>

<template>
    <div
        class="n-rte"
        :class="{ 'n-rte--error': invalid, 'n-rte--disabled': disabled }"
    >
        <div class="n-rte__toolbar" role="toolbar" :aria-label="labels.toolbar">
            <template v-for="(tool, i) in visibleTools">
                <span
                    v-if="tool === 'sep'"
                    :key="'sep' + i"
                    class="n-rte__sep"
                />
                <button
                    v-else
                    :key="tool.id"
                    type="button"
                    class="n-rte__btn"
                    :class="{
                        'n-rte__btn--text': tool.text,
                        'is-active': tool.key && active[tool.key],
                    }"
                    :title="labels[tool.id]"
                    :aria-label="labels[tool.id]"
                    :aria-pressed="tool.key ? active[tool.key] : undefined"
                    :disabled="disabled"
                    @mousedown.prevent="run(tool)"
                >
                    <span v-if="tool.text">{{ tool.text }}</span>
                    <NIcon v-else :name="tool.icon" :size="16" />
                </button>
            </template>
        </div>

        <div
            ref="editable"
            class="n-rte__content"
            :class="{ 'is-empty': isEmpty }"
            :contenteditable="disabled ? 'false' : 'true'"
            :data-placeholder="placeholder"
            :aria-invalid="invalid || undefined"
            :aria-required="field?.required.value || undefined"
            :aria-labelledby="field?.labelledby.value || undefined"
            :aria-describedby="field?.describedBy.value || undefined"
            role="textbox"
            aria-multiline="true"
            @input="sync"
            @paste="onPaste"
            @keyup="updateActive"
            @mouseup="updateActive"
        />

        <NModal
            v-model="linkModalOpen"
            :title="labels.linkTitle"
            width="400px"
            :close-label="labels.linkCancel"
        >
            <div ref="linkBody" class="n-rte__link-body">
                <label class="n-rte__link-label">{{ labels.linkPrompt }}</label>
                <NInput
                    v-model="linkUrl"
                    type="url"
                    placeholder="https://"
                    @keydown.enter.prevent="confirmLink"
                />
            </div>
            <template #footer="{ close }">
                <NButton
                    v-if="editingLink"
                    variant="ghost"
                    tone="danger"
                    type="button"
                    @click="removeLink"
                    >{{ labels.linkRemove }}</NButton
                >
                <span class="n-rte__link-spacer" />
                <NButton variant="ghost" type="button" @click="close">{{
                    labels.linkCancel
                }}</NButton>
                <NButton variant="primary" type="button" @click="confirmLink">{{
                    labels.linkConfirm
                }}</NButton>
            </template>
        </NModal>
    </div>
</template>

<style scoped>
.n-rte {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    overflow: hidden;
    transition: 0.14s;
}
.n-rte:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent);
}
.n-rte--error {
    border-color: var(--danger);
    border-width: 1.5px;
}
.n-rte--disabled {
    background: var(--surface-3);
    opacity: 0.7;
}

/* --- toolbar --- */
.n-rte__toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px;
    padding: 6px 8px;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
}
.n-rte__sep {
    width: 1px;
    align-self: stretch;
    margin: 4px 4px;
    background: var(--border);
}
.n-rte__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 28px;
    padding: 0 6px;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text-2);
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    transition: 0.12s;
}
.n-rte__btn--text {
    min-width: 30px;
}
.n-rte__btn:hover {
    background: var(--surface-3);
    color: var(--text);
}
.n-rte__btn.is-active {
    background: var(--accent-soft);
    color: var(--accent);
}
.n-rte__btn:disabled {
    color: var(--text-3);
    cursor: not-allowed;
    background: transparent;
}

/* --- link modal --- */
.n-rte__link-body {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.n-rte__link-label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-2);
}
.n-rte__link-spacer {
    flex: 1;
}

/* --- editable area --- */
.n-rte__content {
    min-height: 140px;
    max-height: 420px;
    overflow-y: auto;
    padding: 12px 14px;
    color: var(--text);
    font-family: inherit;
    font-size: var(--fs);
    line-height: 1.6;
    outline: none;
}
.n-rte__content.is-empty::before {
    content: attr(data-placeholder);
    color: var(--text-3);
    pointer-events: none;
}

/* --- prose styling (innerHTML content needs :deep, it has no scope id) --- */
.n-rte__content :deep(h2) {
    font-size: 1.3em;
    font-weight: 800;
    letter-spacing: -0.01em;
    margin: 0.6em 0 0.3em;
}
.n-rte__content :deep(h3) {
    font-size: 1.12em;
    font-weight: 700;
    margin: 0.6em 0 0.3em;
}
.n-rte__content :deep(p) {
    margin: 0 0 0.6em;
}
.n-rte__content :deep(ul),
.n-rte__content :deep(ol) {
    margin: 0 0 0.6em;
    padding-left: 1.5em;
}
.n-rte__content :deep(a) {
    color: var(--accent);
    text-decoration: underline;
}
.n-rte__content :deep(blockquote) {
    margin: 0 0 0.6em;
    padding-left: 12px;
    border-left: 3px solid var(--border-2);
    color: var(--text-2);
}
.n-rte__content :deep(code) {
    padding: 1px 5px;
    border-radius: 5px;
    background: var(--surface-3);
    font-family: var(--font-mono);
    font-size: 0.9em;
}
</style>
