import { expect, test as setup } from "@playwright/test";

// Logs in once through the form; the admin specs reuse the session.
setup("administrator signs in", async ({ page }) => {
    await page.goto("/admin/login");
    await page.getByLabel("Email").fill("admin@example.com");
    await page.getByLabel("Пароль", { exact: true }).fill("password123");
    await page.getByRole("button", { name: "Войти" }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await page.context().storageState({
        path: "storage/framework/testing/e2e-results/admin-state.json",
    });
});
