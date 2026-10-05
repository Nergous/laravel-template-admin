import { expect, test } from "@playwright/test";

// Navigation and list patterns shared by the admin pages.

test("breadcrumbs show the path and lead back to the list", async ({
    page,
}) => {
    await page.goto("/admin/users/create");
    const crumbs = page.getByRole("navigation", {
        name: "Навигационная цепочка",
    });
    await expect(crumbs).toBeVisible();
    await expect(crumbs.locator("[aria-current=page]")).toHaveText(
        "Новый пользователь",
    );
    await crumbs.getByRole("link", { name: "Пользователи" }).click();
    await expect(page).toHaveURL(/\/admin\/users$/);
});

test("filter chips remove one filter or reset them all", async ({ page }) => {
    await page.goto("/admin/users?search=adm&status=blocked");
    const chips = page.getByRole("group", { name: "Применённые фильтры" });
    await expect(chips).toContainText("Заблокированные");
    await expect(chips).toContainText("adm");

    await chips.getByRole("button", { name: "Убрать фильтр «Поиск»" }).click();
    await expect(page).toHaveURL(/status=blocked/);
    await expect(page).not.toHaveURL(/search=adm/);
    await expect(chips).not.toContainText("adm");

    await chips.getByRole("button", { name: "Сбросить все" }).click();
    await expect(page).not.toHaveURL(/status=blocked/);
    await expect(chips).toBeHidden();
});

test("the settings tab lives in the address", async ({ page }) => {
    await page.goto("/admin/settings");
    await page.getByRole("tab", { name: "SEO" }).click();
    await expect(page).toHaveURL(/\/admin\/settings\?tab=seo$/);

    await page.reload();
    await expect(page.getByRole("tab", { name: "SEO" })).toHaveAttribute(
        "aria-selected",
        "true",
    );

    await page.goto("/admin/settings?tab=security");
    await expect(
        page.getByRole("tab", { name: "Безопасность" }),
    ).toHaveAttribute("aria-selected", "true");

    await page.getByRole("tab", { name: "Общие" }).click();
    await expect(page).toHaveURL(/\/admin\/settings$/);
});

test("the save bar follows unsaved changes", async ({ page }) => {
    await page.goto("/admin/settings");
    const save = page.getByRole("button", { name: "Сохранить", exact: true });
    await expect(page.getByText("Изменений нет")).toBeVisible();
    await expect(save).toBeDisabled();

    await page.getByLabel("Название приложения").fill("Admin e2e");
    await expect(page.getByText("Есть несохранённые изменения")).toBeVisible();
    await expect(save).toBeEnabled();

    await page.getByRole("button", { name: "Отменить изменения" }).click();
    await expect(page.getByText("Изменений нет")).toBeVisible();
});

test("icon-only buttons explain themselves on hover", async ({ page }) => {
    await page.goto("/admin");
    const tip = page.locator(".n-itip");
    await page.getByRole("button", { name: /^Уведомления/ }).hover();
    await expect(tip).toHaveText(/^Уведомления/);

    await page.keyboard.press("Escape");
    await expect(tip).toHaveCount(0);
});

test("the dashboard offers the main actions", async ({ page }) => {
    await page.goto("/admin");
    await expect(
        page.getByRole("link", { name: "Создать пользователя" }),
    ).toHaveAttribute("href", /\/admin\/users\/create$/);
    await expect(
        page.getByRole("link", { name: "Загрузить файлы" }),
    ).toBeVisible();
});

test.describe("on a phone", () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test("table rows become labelled cards without sideways scrolling", async ({
        page,
    }) => {
        await page.goto("/admin/users");
        // The checkbox column has no header, so its cell has no label.
        const cell = page
            .locator(
                "table.n-table tbody tr td[data-label]:not([data-label=''])",
            )
            .first();
        await expect(cell).toBeVisible();
        await expect(cell).toHaveCSS("display", "grid");
        await expect(page.locator("table.n-table thead")).toHaveCSS(
            "position",
            "absolute",
        );
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );
        expect(overflow).toBeLessThanOrEqual(0);
    });

    test("the topbar controls do not cover each other", async ({ page }) => {
        await page.goto("/admin");
        const search = await page
            .getByRole("button", { name: "Поиск и команды" })
            .boundingBox();
        const bell = await page
            .getByRole("button", { name: /^Уведомления/ })
            .boundingBox();
        expect(search && bell).toBeTruthy();
        expect(search!.x + search!.width).toBeLessThanOrEqual(bell!.x);
    });
});
