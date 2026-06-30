<script setup>
/**
 * AuthLayout — two-panel split used by auth screens (login, future password
 * reset). The left panel hosts the form (default slot); the right panel shows
 * page-specific marketing copy via the #aside slot and collapses below 860px.
 *
 * Importing useTheme here ensures the persisted light/dark + density tokens are
 * applied to <html> even on pages that render no sidebar/topbar.
 */
import { Head } from "@inertiajs/vue3";
import { useTheme } from "@/lib/nergous-cit";

// `title` feeds the document <title> via app.js's title template; auth pages
// render no topbar, so this is their only title source.
defineProps({
    title: { type: String, default: "" },
});

useTheme();
</script>

<template>
    <div class="auth">
        <Head :title="title" />
        <div class="auth__form">
            <div class="auth__inner">
                <slot />
            </div>
        </div>
        <aside v-if="$slots.aside" class="auth__side">
            <div class="auth__side-inner">
                <slot name="aside" />
            </div>
        </aside>
    </div>
</template>

<style scoped>
.auth {
    min-height: 100vh;
    display: flex;
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-sans);
}
.auth__form {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px;
}
.auth__inner {
    width: 100%;
    max-width: 380px;
}
.auth__side {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px;
    color: #fff;
    background: linear-gradient(150deg, #0066ff, #3384ff 60%, #4aa3ff);
}
.auth__side-inner {
    max-width: 380px;
}
@media (max-width: 860px) {
    .auth__side {
        display: none;
    }
}
</style>
