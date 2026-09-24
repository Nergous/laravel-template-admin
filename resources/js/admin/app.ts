import { createApp, h } from "vue";
import type { DefineComponent } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import "nergous-ui-vue/styles";
import "@/admin/styles.css";

const appName = import.meta.env.VITE_APP_NAME || "Admin Panel";

// Use the active theme's accent for Inertia's progress bar.
const accent =
    getComputedStyle(document.documentElement)
        .getPropertyValue("--accent")
        .trim() || "#0066ff";

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) =>
        (
            await resolvePageComponent(
                `./pages/${name}.vue`,
                import.meta.glob<{ default: DefineComponent }>(
                    "./pages/**/*.vue",
                ),
            )
        ).default,
    progress: {
        color: accent,
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
