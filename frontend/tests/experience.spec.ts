import { expect, test } from "@playwright/test";

test("P06: refreshed dashboard and modular navigation preserve real routes", async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) {
    throw new Error("Set DEMO_PASSWORD for the existing synthetic demo account.");
  }
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();

  await expect(page.getByRole("heading", { name: "Pilotez votre activité avec une seule vue." })).toBeVisible();
  await expect(page.getByRole("region", { name: "Accès rapides" })).toBeVisible();
  // Share the existing admin login with the role-dashboard checks so
  // browser smoke stays under Laravel's per-account login throttle.
  const support = page.getByRole("region", { name: "Pilotage support" });
  await expect(support).toBeVisible();
  await expect(support.getByText("Incidents en cours")).toBeVisible();
  await expect(support.getByText("Escalades N3")).toBeVisible();
  await expect(support.getByText("SLA résolution dépassé")).toBeVisible();
  await expect(support.locator("article.atlas-role-stat")).toHaveCount(4);
  await expect(page.getByRole("heading", { name: "Le rythme de vos ventes" })).toBeVisible();
  await expect(page.getByRole("combobox", { name: "Entreprise active" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Voir les modules prévus" })).toBeVisible();

  // Future modules are hidden by default and remain disabled if shown.
  await expect(page.getByRole("button", { name: "Tableau de bord direction" })).toHaveCount(0);
  await page.getByRole("button", { name: "Voir les modules prévus" }).click();
  await expect(page.getByRole("button", { name: "Tableau de bord direction" })).toBeDisabled();
  await page.getByRole("button", { name: "Masquer les modules prévus" }).click();
  await expect(page.getByRole("button", { name: "Tableau de bord direction" })).toHaveCount(0);

  // Quick actions use the original routes and permissions, never mock API data.
  await page.getByRole("region", { name: "Accès rapides" })
    .getByRole("button", { name: /Commandes/ }).click();
  await expect(page.getByRole("heading", { name: "Commandes." })).toBeVisible();
  await page.getByRole("button", { name: "Vue d’ensemble" }).click();
  await expect(page.getByRole("heading", { name: "Pilotez votre activité avec une seule vue." })).toBeVisible();
});
