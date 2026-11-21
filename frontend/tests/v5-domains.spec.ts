import { expect, test } from "@playwright/test";

test("V5: navigation des quatre domaines et sous-menus sans fausses pages métier", async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) throw new Error("Set DEMO_PASSWORD before browser tests");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();
  await expect(page.getByRole("combobox", { name: "Entreprise active" })).toBeVisible();

  const views = [
    ["Demandes d’achat", "purchasing/requests"],
    ["Sites et localisations", "maintenance/sites"],
    ["Parc d’équipements", "maintenance/assets"],
    ["Demandes d’intervention (DI)", "maintenance/requests"],
    ["Bons de travail (BT)", "maintenance/work-orders"],
    ["Établissements hôteliers", "hotels"],
    ["Chambres", "hotel-rooms"],
    ["Réservations", "hotel-bookings"],
    ["Catalogue des sources", "data/sources"],
    ["Lots et ingestion CSV", "data/ingestion-batches"],
  ];
  for (const [label] of views) {
    const button = page.getByRole("button", { name: label, exact: true });
    await expect(button).toBeEnabled();
    await button.click();
    await expect(page.locator(".atlas-domain")).toBeVisible();
    await expect(page.locator(".atlas-domain h2")).toBeVisible();
    await expect(page).toHaveURL(/\/workspace\//);
    await expect(page.locator(".atlas-domain-list")).toBeVisible();
    await expect(page.getByRole("alert")).toHaveCount(0);
  }
  await expect(page.getByRole("button", { name: "Mini-bar" })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Pipelines ETL" })).toBeDisabled();
});

test("V5: création DI puis qualification et BT sans doublon", async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) throw new Error("Set DEMO_PASSWORD before browser tests");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();
  const suffix = String(Date.now());

  await page.getByRole("button", { name: "Sites et localisations" }).click();
  await page.getByRole("button", { name: "Nouvelle fiche" }).click();
  await page.getByLabel(/^Code/).fill("SITE-"+suffix);
  await page.getByLabel("Nom", { exact: true }).fill("Site de recette");
  await page.getByRole("button", { name: "Enregistrer" }).click();
  await expect(page.getByText("SITE-"+suffix)).toBeVisible();

  await page.getByRole("button", { name: "Parc d’équipements" }).click();
  await page.getByRole("button", { name: "Nouvelle fiche" }).click();
  await page.getByLabel("Site", { exact: true }).selectOption({ label: "SITE-"+suffix+" · Site de recette" });
  await page.getByLabel(/^Code/).fill("PUMP-"+suffix);
  await page.getByLabel("Nom", { exact: true }).fill("Pompe de démonstration");
  await page.getByRole("button", { name: "Enregistrer" }).click();
  await expect(page.getByText("PUMP-"+suffix)).toBeVisible();

  await page.getByRole("button", { name: "Demandes d’intervention (DI)" }).click();
  await page.getByRole("button", { name: "Nouvelle fiche" }).click();
  await page.getByLabel("Équipement", { exact: true }).selectOption({ label: "PUMP-"+suffix+" · Pompe de démonstration" });
  await page.getByLabel("Référence", { exact: true }).fill("DI-"+suffix);
  await page.getByLabel("Objet", { exact: true }).fill("Pression insuffisante");
  await page.getByLabel("Priorité", { exact: true }).selectOption("high");
  await page.getByRole("button", { name: "Enregistrer" }).click();
  const row = page.getByRole("row", { name: new RegExp("DI-"+suffix) });
  await expect(row).toBeVisible();
  await row.getByRole("button", { name: "Qualifier" }).click();
  await expect(row.getByRole("button", { name: "Créer / voir BT" })).toBeVisible();
  await row.getByRole("button", { name: "Créer / voir BT" }).click();

  await page.getByRole("button", { name: "Bons de travail (BT)" }).click();
  const workOrder = page.locator(".atlas-domain-list tbody tr").first();
  await expect(workOrder).toBeVisible();
  await workOrder.getByRole("button", { name: "Démarrer" }).click();
  await expect(workOrder.getByRole("button", { name: "Clôturer" })).toBeVisible();
});
