import { createApp, h } from "vue";
import type { DefineComponent } from "vue";
import { createInertiaApp, router } from "@inertiajs/vue3";
import { createLocale, installEnterSubmit, ruMessages } from "nergous-ui-vue";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import "nergous-ui-vue/styles";
import "@/admin/styles.css";
import { setDisplayTimeZone } from "@/lib/format";
import { rememberListUrl, rememberPreviousPage } from "@/lib/listUrl";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";

// Enter in a text field clicks the nearest [data-enter-submit] button of the
// dialog or the page (the main area bounds the search).
installEnterSubmit({ boundary: "#admin-main" });

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

// Pages that render without the admin chrome: sign-in screens use AuthLayout
// inside the page, the error page is a standalone screen.
function usesAdminLayout(name: string): boolean {
    return !name.startsWith("Auth/") && name !== "Error";
}

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
    // Persistent layout: the sidebar, topbar, toaster, session timer and
    // notification polling stay mounted across visits; pages set the header
    // through usePageHeader().
    layout: (name) => (usesAdminLayout(name) ? AdminLayout : null),
    progress: {
        color: accent,
    },
    setup({ el, App, props, plugin }) {
        applySharedProps(props.initialPage.props);
        rememberListUrl(props.initialPage.url);
        rememberPreviousPage(props.initialPage.url);
        router.on("navigate", (event) => {
            applySharedProps(event.detail.page.props);
            rememberListUrl(event.detail.page.url);
            rememberPreviousPage(event.detail.page.url);
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            // Russian labels for every nergous-ui-vue component.
            .use(createLocale(ruMessages))
            .mount(el);
    },
});
