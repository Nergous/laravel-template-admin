import { createApp, h } from "vue";
import type { DefineComponent } from "vue";
import { createInertiaApp, router } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import "nergous-ui-vue/styles";
import "@/admin/styles.css";
import { setDisplayTimeZone } from "@/lib/format";
import { rememberListUrl } from "@/lib/listUrl";

// The tab title follows the "Название приложения" setting (shared prop appName);
// VITE_APP_NAME is only a fallback before the first page props arrive.
let appName: string = import.meta.env.VITE_APP_NAME || "Admin Panel";

function applySharedProps(props: Record<string, unknown>) {
    if (typeof props.appName === "string" && props.appName)
        appName = props.appName;
    setDisplayTimeZone(
        typeof props.timezone === "string" ? props.timezone : null,
    );
}

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
        applySharedProps(props.initialPage.props);
        rememberListUrl(props.initialPage.url);
        router.on("navigate", (event) => {
            applySharedProps(event.detail.page.props);
            rememberListUrl(event.detail.page.url);
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
