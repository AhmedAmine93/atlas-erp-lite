import { expect, test } from "@playwright/test";

async function login(page: import("@playwright/test").Page, role: string) {
  if (!process.env.DEMO_PASSWORD) {
    throw new Error("DEMO_PASSWORD must be set for synthetic demo tests.");
  }
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill(`${role}@atlas.example`);
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Pilotez votre activité avec une seule vue." })).toBeVisible();
}

test("P06.05: admin sees tenant-scoped support summary and original finance dashboard", async ({ page }) => {
  await login(page, "admin");
  const support = page.getByRole("region", { name: "Pilotage support" });
  await expect(support).toBeVisible();
  await expect(support.getByText("Incidents en cours")).toBeVisible();
  await expect(support.getByText("Escalades N3")).toBeVisible();
  await expect(support.getByText("SLA résolution dépassé")).toBeVisible();
  await expect(page.getByRole("heading", { name: "Le rythme de vos ventes" })).toBeVisible();
  await support.getByRole("button", { name: "Ouvrir les incidents" }).click();
  await expect(page.getByRole("heading", { name: "Centre des incidents" })).toBeVisible();
});

test("P06.05: stock user sees real inventory priorities without finance panels", async ({ page }) => {
  await login(page, "stock");
  const quick = page.getByRole("region", { name: "Accès rapides" });
  await expect(quick.getByRole("button", { name: /Mouvements/ })).toBeVisible();
  await expect(quick.getByRole("button", { name: /Incidents/ })).toHaveCount(0);
  const stocks = page.getByRole("region", { name: "Priorités stocks" });
  await expect(stocks).toBeVisible();
  await expect(stocks.getByRole("heading", { name: "Vos priorités d'inventaire" })).toBeVisible();
  await expect(stocks.getByText("Produits au seuil d'alerte ou en dessous")).toBeVisible();
  await expect(page.getByRole("heading", { name: "Le rythme de vos ventes" })).toHaveCount(0);
  await expect(page.getByRole("region", { name: "Pilotage support" })).toHaveCount(0);
  await stocks.getByRole("button", { name: "Ouvrir les stocks" }).click();
  await expect(page.getByRole("heading", { name: "Produits & stocks." })).toBeVisible();
});

test("P06.05: commercial user sees sales priorities and follows invoice shortcut", async ({ page }) => {
  await login(page, "commercial");
  const commercial = page.getByRole("region", { name: "Priorités commerciales" });
  await expect(commercial).toBeVisible();
  await expect(commercial.getByRole("heading", { name: "Votre suivi commercial" })).toBeVisible();
  await expect(commercial.getByText("À encaisser TTC")).toBeVisible();
  await commercial.getByRole("button", { name: "À encaisser TTC" }).click();
  await expect(page.getByRole("heading", { name: "Factures." })).toBeVisible();
});

test("P06.05: reader retains original overview without privileged support summary", async ({ page }) => {
  await login(page, "reader");
  await expect(page.getByRole("region", { name: "Pilotage support" })).toHaveCount(0);
  await expect(page.getByRole("region", { name: "Priorités commerciales" })).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "Le rythme de vos ventes" })).toBeVisible();
});
