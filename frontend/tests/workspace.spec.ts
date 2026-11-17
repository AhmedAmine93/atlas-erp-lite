import { test, expect } from "@playwright/test";

test.beforeEach(async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) throw new Error("Set DEMO_PASSWORD before running browser tests.");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Le rythme de vos ventes" })).toBeVisible();
});

test("modal : clavier, fermeture, focus et historique client", async ({ page }) => {
  await page.getByRole("button", { name: "Clients", exact: true }).click();
  const create = page.getByRole("button", { name: "Nouveau client", exact: true });
  await create.click();
  const modal = page.getByRole("dialog");
  await expect(modal).toBeFocused();
  await page.keyboard.press("Shift+Tab");
  await expect(modal.getByRole("button", { name: "Enregistrer", exact: true })).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(modal.getByRole("button", { name: "Fermer", exact: true })).toBeFocused();
  await page.getByLabel("Nom / entreprise", { exact: true }).fill("Saisie non enregistrée");
  await page.keyboard.press("Escape");
  await expect(modal).toBeHidden();
  await expect(create).toBeFocused();
  expect(await page.evaluate(() => document.body.style.overflow)).not.toBe("hidden");

  await create.click();
  await modal.getByRole("button", { name: "Fermer", exact: true }).click();
  await expect(modal).toBeHidden();
  await create.click();
  await page.locator(".modal-backdrop").click({ position: { x: 4, y: 4 } });
  await expect(modal).toBeHidden();

  await page.getByRole("button", { name: "Historique", exact: true }).first().click();
  await expect(modal.getByRole("heading", { name: "Historique des commandes" })).toBeVisible();
  await expect(modal.getByText("Les 100 commandes les plus récentes.")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(modal).toBeHidden();
});

test("détail facture : erreur PDF récupérable sans fermer le document", async ({ page }) => {
  await page.getByRole("button", { name: "Factures", exact: true }).click();
  await page.getByRole("button", { name: "Ouvrir" }).first().click();
  const modal = page.getByRole("dialog");
  await expect(modal.getByText("Total TTC", { exact: false })).toBeVisible();
  await page.route("**/api/invoices/*/pdf", (route) =>
    route.fulfill({ status: 503, contentType: "application/json", body: '{"message":"Test PDF indisponible"}' }),
  );
  await modal.getByRole("button", { name: "Télécharger le PDF" }).click();
  await expect(modal.getByRole("alert")).toHaveText("La génération du PDF a échoué.");
  await expect(modal).toBeVisible();
  await page.unroute("**/api/invoices/*/pdf");
  const download = page.waitForEvent("download");
  await modal.getByRole("button", { name: "Télécharger le PDF" }).click();
  expect((await download).suggestedFilename()).toMatch(/^FAC-.*\.pdf$/);
  await expect(modal.getByRole("alert")).toHaveCount(0);
});
