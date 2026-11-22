import { expect, test } from "@playwright/test";

test("M01: all five pilotage sections render with tenant-backed indicators", async ({ page }) => {
  const password = process.env.DEMO_PASSWORD;
  if (!password) throw new Error("DEMO_PASSWORD is required for the M01 browser test.");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("v5-browser-e2e-pilotage@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();

  const pages = [
    ["Tableau de bord direction", "Consolidation au niveau entreprise"],
    ["Pilotage commercial", "Volumes de devis"],
    ["Pilotage financier", "Encours et factures"],
    ["Pilotage logistique", "Stock physique"],
    ["Alertes et objectifs", "Règles datées"],
  ];
  for (const [menu, explanation] of pages) {
    await page.getByRole("button", { name: menu, exact: true }).click();
    await expect(page.getByRole("heading", { name: menu, exact: true }).last()).toBeVisible();
    await expect(page.getByText(explanation, { exact: false })).toBeVisible();
    await expect(page.getByText("Entreprise active uniquement").first()).toBeVisible();
    await expect(page.getByRole("heading", { name: "Définitions et limites des indicateurs" })).toBeVisible();
  }
  await expect(page.getByRole("heading", { name: "Nouvelle règle de surveillance" })).toBeVisible();
});
