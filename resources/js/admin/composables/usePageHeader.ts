import { watchEffect } from "vue";
import { setLayoutProps } from "@inertiajs/vue3";

/** A parent page in the breadcrumb trail; the current page is added by the layout. */
export interface Crumb {
    label: string;
    href: string;
}

export interface PageHeader {
    title: string;
    subtitle?: string;
    /** Parent pages, outermost first. The layout appends the current title. */
    crumbs?: Crumb[];
}

/**
 * Title and subtitle of the persistent AdminLayout (topbar and tab title).
 *
 * The layout stays mounted between visits, so a page cannot pass it props or
 * slots; it publishes its header through Inertia layout props instead. The
 * getter is reactive: a subtitle built from page props follows partial
 * reloads. Inertia clears layout props on every visit that replaces the page,
 * so a page never shows the header of the previous one.
 */
export function usePageHeader(header: () => PageHeader): void {
    watchEffect(() => {
        const { title, subtitle = "", crumbs = [] } = header();
        setLayoutProps({ title, subtitle, crumbs });
    });
}
