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

  // M01 direction is now an authorized real route; untouched future modules
  // (such as CRM Prospects) remain hidden and non-navigable.
  await expect(page.getByRole("button", { name: "Tableau de bord direction" })).toBeEnabled();
  await expect(page.getByRole("button", { name: "Pipelines ETL", exact: true })).toHaveCount(0);
  await page.getByRole("button", { name: "Voir les modules prévus" }).click();
  await expect(page.getByRole("button", { name: "Pipelines ETL", exact: true })).toBeDisabled();
  await page.getByRole("button", { name: "Masquer les modules prévus" }).click();
  await expect(page.getByRole("button", { name: "Pipelines ETL", exact: true })).toHaveCount(0);

  // Quick actions use the original routes and permissions, never mock API data.
  await page.getByRole("region", { name: "Accès rapides" })
    .getByRole("button", { name: /Commandes/ }).click();
  await expect(page.getByRole("heading", { name: "Commandes." })).toBeVisible();
  await page.getByRole("button", { name: "Vue d’ensemble" }).click();
  await expect(page.getByRole("heading", { name: "Pilotez votre activité avec une seule vue." })).toBeVisible();

  // P066.02: navigation has stable URLs, supports refresh and browser history.
  await page.getByRole("button", { name: "Clients", exact: true }).click();
  await expect(page).toHaveURL(/\/workspace\/customers$/);
  await expect(page.getByRole("heading", { name: "Clients." })).toBeVisible();
  await page.reload();
  await expect(page.getByRole("heading", { name: "Clients." })).toBeVisible();

  // Customer history opens as an addressable record, revalidated on reload.
  await page.getByRole("button", { name: "Historique" }).first().click();
  await expect(page).toHaveURL(/\/workspace\/customers\/\d+$/);
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.reload();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.getByRole("button", { name: "Fermer", exact: true }).click();
  await expect(page).toHaveURL(/\/workspace\/customers$/);

  await page.getByRole("button", { name: "Vue d’ensemble" }).click();
  await expect(page).toHaveURL(/\/workspace\/dashboard$/);
  await page.goBack();
  await expect(page.getByRole("heading", { name: "Clients." })).toBeVisible();
  await page.getByRole("button", { name: "Vue d’ensemble" }).click();

  // P066.03 global search: reuse the authenticated administrator session.
  await page.keyboard.press("Control+k");
  const searchInput = page.getByRole("searchbox", { name: "Recherche globale" });
  await expect(searchInput).toBeVisible();
  await searchInput.fill("BUR-001");
  const searchResults = page.getByRole("region", { name: "Résultats de recherche" });
  await expect(searchResults.getByRole("button", { name: /BUR-001/ })).toBeVisible();
  await searchResults.getByRole("button", { name: /BUR-001/ }).first().click();
  await expect(page.getByRole("heading", { name: "Produits & stocks." })).toBeVisible();
  await expect(page).toHaveURL(/\/workspace\/products$/);
  await page.getByRole("button", { name: "Vue d’ensemble" }).click();

  // Dashboard Builder: real widgets can be hidden, moved, and restored, with
  // preferences kept on the same browser for this user and active company.
  await page.getByRole("button", { name: "Personnaliser le tableau de bord" }).click();
  let settings = page.getByRole("region", { name: "Personnalisation du tableau de bord" });
  const chartToggle = settings.getByRole("checkbox", { name: "Le rythme de vos ventes" });
  await expect(chartToggle).toBeChecked();
  await chartToggle.uncheck();
  await expect(page.getByRole("heading", { name: "Le rythme de vos ventes" })).toHaveCount(0);
  await settings.getByRole("checkbox", { name: "Chiffre d’affaires" }).uncheck();
  await expect(page.locator('.stats-grid > [data-widget="revenue"]')).toHaveCount(0);
  await settings.getByRole("button", { name: "Monter Les dernières commandes" }).click();
  await expect(page.locator(".dashboard-grid > section").first())
    .toHaveAttribute("data-widget", "recent-orders");

  // Same browser session; no additional login and no auth-rate-limit change.
  await page.reload();
  await expect(page.getByRole("heading", { name: "Pilotez votre activité avec une seule vue." })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Le rythme de vos ventes" })).toHaveCount(0);
  await expect(page.locator('.stats-grid > [data-widget="revenue"]')).toHaveCount(0);
  await expect(page.locator(".dashboard-grid > section").first())
    .toHaveAttribute("data-widget", "recent-orders");

  await page.getByRole("button", { name: "Personnaliser le tableau de bord" }).click();
  settings = page.getByRole("region", { name: "Personnalisation du tableau de bord" });
  await settings.getByRole("button", { name: "Réinitialiser" }).click();
  await expect(page.getByRole("heading", { name: "Le rythme de vos ventes" })).toBeVisible();
  await expect(page.locator('.stats-grid > [data-widget="revenue"]')).toBeVisible();
  await expect(page.locator(".dashboard-grid > section").first())
    .toHaveAttribute("data-widget", "revenue-chart");

  // P066.05: the active company is stored in the server-side session shared
  // across tabs. A switch in this tab must invalidate the sibling tab without
  // ever rendering its previous tenant's CRM screen again.
  const activeSelect = page.getByRole("combobox", { name: "Entreprise active" });
  const initialCompany = await activeSelect.inputValue();
  await page.getByRole("button", { name: "Entreprises et établissements" }).click();
  await expect(page.getByRole("heading", { name: "Entreprises et établissements." })).toBeVisible();
  await page.getByLabel("Code entreprise").fill("TAB_ISOLATION_B");
  await page.getByLabel("Raison sociale").fill("Entreprise de test multi-onglet");
  await page.getByRole("button", { name: "Enregistrer l’entreprise" }).click();
  await expect(page.getByRole("status").filter({ hasText: "Entreprise enregistrée." })).toBeVisible();
  const nextCompany = await page.getByRole("combobox", { name: "Entreprise active" })
    .locator("option", { hasText: "Entreprise de test multi-onglet" }).getAttribute("value");
  expect(nextCompany).toBeTruthy();

  const sibling = await page.context().newPage();
  await sibling.goto("/workspace/customers");
  await expect(sibling.getByRole("heading", { name: "Clients." })).toBeVisible();
  await expect(sibling.getByText("Maison Juniper")).toBeVisible();

  await activeSelect.selectOption(nextCompany!);
  await expect(page.getByRole("combobox", { name: "Entreprise active" })).toHaveValue(nextCompany!);
  await expect(sibling.getByRole("heading", { name: "Vue d’ensemble." })).toBeVisible();
  await expect(sibling.getByRole("combobox", { name: "Entreprise active" })).toHaveValue(nextCompany!);
  await expect(sibling.getByText("Maison Juniper")).toHaveCount(0);

  // Both tabs must see the same verified company; switching back is symmetric.
  await page.getByRole("combobox", { name: "Entreprise active" }).selectOption(initialCompany);
  await expect(sibling.getByRole("combobox", { name: "Entreprise active" })).toHaveValue(initialCompany);
  await sibling.close();
});
