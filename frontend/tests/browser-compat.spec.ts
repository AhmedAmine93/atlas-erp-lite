import { expect, test } from "@playwright/test";

// P066.06 — same test runs on Firefox and WebKit through compat config.
// This route is public, so compatibility checks do not consume rate-limited
// synthetic account logins or mutate fixtures.
test("P066.06: deep links, semantic login and keyboard controls across engines", async ({ page }) => {
  const response = await page.goto("/workspace/customers");
  expect(response?.status()).toBe(200);
  await expect(page).toHaveTitle(/ATLAS ONE/);
  await expect(page.locator("html")).toHaveAttribute("lang", "fr");
  await expect(page.getByRole("heading", { name: "Heureux de vous retrouver." })).toBeVisible();
  await expect(page.getByRole("textbox", { name: "Adresse e-mail" })).toBeVisible();
  await expect(page.getByLabel("Mot de passe", { exact: true })).toHaveAttribute("type", "password");
  await expect(page.getByRole("button", { name: "Se connecter" })).toBeEnabled();
  const html = await page.locator("html").evaluate((node) => node.outerHTML);
  expect(html).not.toContain("Maison Juniper");
  expect(html).not.toContain("admin@atlas.example@");

  await page.getByRole("textbox", { name: "Adresse e-mail" }).focus();
  await expect(page.getByRole("textbox", { name: "Adresse e-mail" })).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(page.getByLabel("Mot de passe", { exact: true })).toBeFocused();
});

test("P066.06: public login supports narrow mobile and tablet widths", async ({ page }) => {
  for (const width of [360, 390, 768, 1280]) {
    await page.setViewportSize({ width, height: 844 });
    await page.goto("/");
    await expect(page.getByRole("button", { name: "Se connecter" })).toBeVisible();
    const overflow = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
    );
    expect(overflow, `Unexpected horizontal overflow at width ${width}`).toBe(false);
  }
});
