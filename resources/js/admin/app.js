import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import "@/lib/nergous-cit/styles/tokens.css";
import "@/admin/styles.css";

const appName = import.meta.env.VITE_APP_NAME || "Admin Panel";

// tokens.css is imported above, so --accent is resolvable on <html> at boot.
// The progress bar tracks the theme accent instead of a hardcoded literal.
const accent =
    getComputedStyle(document.documentElement)
        .getPropertyValue("--accent")
        .trim() || "#0066ff";

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob("./pages/**/*.vue"),
        ),
    progress: {
        color: accent,
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
