<script setup>
import NIcon from "../primitives/NIcon.vue";
import NBrand from "./NBrand.vue";

// NSidebar — collapsible navigation rail. v-model is the active item id;
// v-model:collapsed toggles the narrow icon-only mode. #footer slot receives { collapsed }.
// groups: [{ label, items: [{ id, label, icon, badge }] }]
// brand:  { name, sub, glyph }
// If an item has an `href` it renders as `linkAs` (defaults to <a>); pass a
// router link component (e.g. Inertia <Link>) via `linkAs`. The active item is
// driven by `modelValue` (derive it from the current route in the parent) so the
// component stays framework-agnostic.
defineProps({
    modelValue: { type: [String, Number], default: "" }, // active item id
    groups: { type: Array, default: () => [] },
    collapsed: { type: Boolean, default: false },
    // Off-canvas mode for mobile/narrow viewports. When true the sidebar is
    // positioned fixed over the content and shown/hidden via `collapsed`:
    //   mobile && !collapsed → drawer open (slid in)
    //   mobile && collapsed  → drawer hidden (slid out off-screen)
    // Desktop behaviour (mobile=false) is unchanged: collapsed = 248/74px width.
    mobile: { type: Boolean, default: false },
    brand: {
        type: Object,
        default: () => ({ name: "nergous-cit", sub: "Operations", glyph: "N" }),
    },
    linkAs: { type: Object, default: () => ({}) },
    // Accessible name for the <nav> landmark (English default; app localizes).
    navLabel: { type: String, default: "Main navigation" },
});
// `navigate` fires on any item activation (link or button) so the host can react
// intentionally — e.g. close a mobile off-canvas drawer — instead of relying on a
// bubbled click. Payload: the activated item.
const emit = defineEmits(["update:modelValue", "update:collapsed", "navigate"]);

function onItemClick(it) {
    if (!it.href) emit("update:modelValue", it.id);
    emit("navigate", it);
}
</script>

<template>
    <aside
        class="n-sb"
        :class="{ collapsed, mobile, 'mobile-open': mobile && !collapsed }"
        :inert="(mobile && collapsed) || undefined"
    >
        <div class="n-sb__brand">
            <NBrand
                :glyph="brand.glyph"
                :name="brand.name"
                :sub="brand.sub"
                size="md"
                :show-sub="!collapsed"
            />
        </div>

        <nav class="n-sb__nav" :aria-label="navLabel">
            <template v-for="(g, gi) in groups" :key="gi">
                <div v-if="!collapsed && g.label" class="n-sb__group">
                    {{ g.label }}
                </div>
                <component
                    :is="it.href ? (it.external ? 'a' : linkAs) : 'button'"
                    v-for="it in g.items"
                    :key="it.id"
                    :href="it.href || undefined"
                    :class="['n-sb__item', { on: modelValue === it.id }]"
                    :title="it.label"
                    :aria-label="collapsed ? it.label : undefined"
                    :aria-current="modelValue === it.id ? 'page' : undefined"
                    @click="onItemClick(it)"
                >
                    <NIcon :name="it.icon" :size="18" class="n-sb__icon" />
                    <span v-if="!collapsed" class="n-sb__label">{{
                        it.label
                    }}</span>
                    <span
                        v-if="!collapsed && it.badge != null"
                        class="n-sb__badge"
                        >{{ it.badge }}</span
                    >
                </component>
            </template>
        </nav>

        <div class="n-sb__foot">
            <slot name="footer" :collapsed="collapsed" />
        </div>
    </aside>
</template>

<style scoped>
.n-sb {
    width: 248px;
    flex: none;
    display: flex;
    flex-direction: column;
    background: var(--sidebar);
    border-right: 1px solid var(--border);
    transition: width 0.24s cubic-bezier(0.4, 0, 0.2, 1);
    height: 100%;
}
.n-sb.collapsed:not(.mobile) {
    width: 74px;
}

/* --- Off-canvas (mobile) --- */
.n-sb.mobile {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 1050;
    width: 248px;
    height: 100%;
    box-shadow: var(--shadow-lg);
    transform: translateX(0);
    transition:
        transform 0.24s cubic-bezier(0.4, 0, 0.2, 1),
        box-shadow 0.24s ease;
    will-change: transform;
}
/* In mobile mode `collapsed` means "hidden": slide the drawer off-screen. */
.n-sb.mobile.collapsed {
    transform: translateX(-100%);
    box-shadow: none;
}
.n-sb__brand {
    height: 64px;
    flex: none;
    display: flex;
    align-items: center;
    padding: 0 18px;
    border-bottom: 1px solid var(--border);
}
.collapsed:not(.mobile) .n-sb__brand {
    justify-content: center;
    padding: 0;
}
.n-sb__nav {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 10px 12px;
}
.n-sb__group {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--text-3);
    padding: 8px 12px 6px;
}
.n-sb__item {
    position: relative;
    display: flex;
    align-items: center;
    gap: 11px;
    width: 100%;
    height: 40px;
    padding: 0 11px;
    margin-bottom: 2px;
    border: none;
    border-radius: var(--radius-md);
    font-family: inherit;
    font-weight: 600;
    font-size: 13.5px;
    color: var(--text-2);
    background: transparent;
    cursor: pointer;
    transition: 0.14s;
    text-decoration: none;
}
.collapsed .n-sb__item {
    justify-content: center;
}
.n-sb__item:hover {
    background: var(--surface-3);
    color: var(--text);
}
.n-sb__item:focus {
    outline: none;
}
.n-sb__item:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: -2px;
}
.n-sb__item.on {
    background: var(--accent-soft);
    color: var(--accent-ink);
    font-weight: 700;
}
.n-sb__icon {
    color: var(--text-3);
    flex: none;
}
.n-sb__item.on .n-sb__icon {
    color: var(--accent-ink);
}
.n-sb__label {
    flex: 1;
    text-align: left;
    white-space: nowrap;
}
.n-sb__badge {
    min-width: 20px;
    height: 19px;
    padding: 0 6px;
    border-radius: 6px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
}
.n-sb__foot {
    flex: none;
    border-top: 1px solid var(--border);
    padding: 10px 12px;
}
/* Visible focus for slotted footer controls (theme toggle, logout). */
.n-sb__foot :deep(button:focus) {
    outline: none;
}
.n-sb__foot :deep(button:focus-visible) {
    outline: 2px solid var(--accent);
    outline-offset: -2px;
}
/* Collapse/slide instantly for users who ask for reduced motion. */
@media (prefers-reduced-motion: reduce) {
    .n-sb,
    .n-sb.mobile {
        transition: none;
    }
}
</style>
