<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { NCommandPalette, useHotkeys, useTheme } from "nergous-ui-vue";
import type { Command } from "nergous-ui-vue";
import { apiFetch } from "@/lib/api";
import { can } from "@/lib/can";
import type { NavItem } from "./nav";

const props = defineProps<{
    /** Sidebar entries the user may open. */
    items: NavItem[];
}>();
const open = defineModel<boolean>({ required: true });

const { theme, toggle } = useTheme();
const commands = ref<Command[]>([]);

const QUICK_ACTIONS = [
    {
        perm: "users.create",
        label: "Создать пользователя",
        icon: "plus",
        href: "/admin/users/create",
    },
    {
        perm: "roles.create",
        label: "Создать роль",
        icon: "plus",
        href: "/admin/roles/create",
    },
    {
        perm: "media.upload",
        label: "Загрузить файлы",
        icon: "upload",
        href: "/admin/media",
    },
    {
        perm: null,
        label: "Мой профиль",
        icon: "user",
        href: "/admin/profile",
    },
];

const baseCommands = computed<Command[]>(() => {
    const nav = props.items.map((it) => ({
        label: it.label,
        hint: "Переход",
        icon: it.icon,
        action: () => router.visit(it.href),
    }));
    const quick = QUICK_ACTIONS.filter((it) => can(it.perm)).map((it) => ({
        label: it.label,
        hint: "Действие",
        icon: it.icon,
        action: () => router.visit(it.href),
    }));
    const actions = [
        {
            label: theme.value === "dark" ? "Светлая тема" : "Тёмная тема",
            hint: "Действие",
            icon: theme.value === "dark" ? "sun" : "moon",
            action: toggle,
        },
    ];
    return [...nav, ...quick, ...actions];
});

// Search is debounced: the request fires after a typing pause, not on every keystroke.
const SEARCH_DEBOUNCE = 200; // ms
const SEARCH_MIN_LEN = 2;
let searchTimer: ReturnType<typeof setTimeout> | null = null;
// Only the latest request may update the results: older ones are aborted.
let searchAbort: AbortController | null = null;

function localMatches(term: string): Command[] {
    const t = term.toLowerCase();
    return baseCommands.value.filter((c) => c.label.toLowerCase().includes(t));
}

function search(q: string) {
    if (searchTimer) clearTimeout(searchTimer);
    searchAbort?.abort();
    const term = q.trim();
    if (term.length < SEARCH_MIN_LEN) {
        commands.value = term ? localMatches(term) : baseCommands.value;
        return;
    }
    searchTimer = setTimeout(() => runSearch(term), SEARCH_DEBOUNCE);
}

async function runSearch(q: string) {
    searchAbort?.abort();
    const controller = new AbortController();
    searchAbort = controller;
    const local = localMatches(q);
    try {
        const res = await apiFetch(`/admin/search?q=${encodeURIComponent(q)}`, {
            signal: controller.signal,
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (controller.signal.aborted || !open.value) return;
        const found = (json.results ?? json).map(
            (r: {
                label: string;
                meta: string;
                icon?: string;
                url: string;
            }) => ({
                label: r.label,
                hint: r.meta,
                icon: r.icon ?? "search",
                action: () => router.visit(r.url),
            }),
        );
        commands.value = [...local, ...found];
    } catch {
        if (!controller.signal.aborted) commands.value = local;
    }
}

// When the palette opens, show the base commands right away.
watch(open, (isOpen) => {
    if (isOpen) commands.value = baseCommands.value;
    else {
        if (searchTimer) clearTimeout(searchTimer);
        searchAbort?.abort();
    }
});

useHotkeys({ "mod+k": () => (open.value = true) });
</script>

<template>
    <NCommandPalette
        v-model="open"
        :commands="commands"
        :filter="false"
        :shortcut="false"
        placeholder="Поиск, переходы, команды…"
        @update:query="search"
    />
</template>
