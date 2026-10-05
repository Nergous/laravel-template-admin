import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

// Automatic accessibility check (axe, WCAG 2.1 A and AA) of the main admin
// pages in both themes. A failure lists each rule with the offending nodes.

const pages = [
    "/admin",
    "/admin/media",
    "/admin/users",
    "/admin/users/create",
    "/admin/roles",
    "/admin/permissions",
    "/admin/queue",
    "/admin/backups",
    "/admin/profile",
    "/admin/settings",
    "/admin/activity-log",
];

async function audit(page: Page) {
    const { violations } = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
        .analyze();
    return violations.map(
        (v) =>
            v.id +
            " (" +
            v.impact +
            "): " +
            v.nodes.map((n) => n.target.join(" ")).join(", "),
    );
}

for (const path of pages) {
    test("no axe violations on " + path, async ({ page }) => {
        await page.goto(path);
        await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
        expect(await audit(page)).toEqual([]);
    });
}

test("no axe violations in the dark theme", async ({ page }) => {
    await page.addInitScript(() =>
        localStorage.setItem("nergous-ui-vue-theme", "dark"),
    );
    await page.goto("/admin/users");
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
    expect(await audit(page)).toEqual([]);
});

test.describe("signed out", () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test("no axe violations on the login page", async ({ page }) => {
        await page.goto("/admin/login");
        await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
        expect(await audit(page)).toEqual([]);
    });

    test("a wrong password keeps the user on the login page", async ({
        page,
    }) => {
        await page.goto("/admin/login");
        await page.getByLabel("Email").fill("admin@example.com");
        await page.getByLabel("Пароль", { exact: true }).fill("wrong-pass");
        await page.getByRole("button", { name: "Войти" }).click();
        await expect(page).toHaveURL(/\/admin\/login$/);
        await expect(page.locator(".n-field__error").first()).toBeVisible();
    });
});
