<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { useTheme } from "nergous-ui-vue";

defineProps({
    title: { type: String, default: "" },
});

// Apply the persisted theme on auth pages, which do not render AdminLayout.
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
