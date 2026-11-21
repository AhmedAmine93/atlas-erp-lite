import { expect, test } from "@playwright/test";

test("F04: connexion, navigation, société créée et changement de tenant", async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) {
    throw new Error("Set DEMO_PASSWORD before running browser tests.");
  }

  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();

  const activeCompany = page.getByRole("combobox", { name: "Entreprise active" });
  await expect(activeCompany).toBeVisible();
  await expect(activeCompany.locator("option")).not.toHaveCount(0);
  const originalCompany = await activeCompany.inputValue();

  await page.getByRole("button", { name: "Clients", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Clients." })).toBeVisible();

  const menuSearch = page.getByRole("searchbox", { name: "Rechercher un menu" });
  await menuSearch.fill("Entreprises et établissements");
  await page.getByRole("button", { name: "Entreprises et établissements" }).click();
  await expect(page.getByRole("heading", { name: "Nouvelle entreprise" })).toBeVisible();
  await expect(page.getByText("Les données commerciales sont isolées par entreprise active.")).toBeVisible();

  // Creating a company must refresh the active-tenant selector without reloading.
  const unique = `F04_${Date.now()}`;
  const companyName = `Entreprise F04 ${unique}`;
  const createCompany = page.locator(".org-form");
  await createCompany.getByLabel("Code entreprise").fill(unique);
  await createCompany.getByLabel("Raison sociale").fill(companyName);
  await createCompany.getByRole("button", { name: "Enregistrer l’entreprise" }).click();
  await expect(page.getByText("Entreprise enregistrée.")).toBeVisible();
  await expect(activeCompany.locator("option", { hasText: companyName })).toHaveCount(1);

  // Changing tenant reloads the shell so no stale view/data survives.
  await activeCompany.selectOption({ label: companyName });
  await expect(page.getByRole("combobox", { name: "Entreprise active" })).toHaveValue(
    await page.getByRole("combobox", { name: "Entreprise active" }).locator("option", { hasText: companyName }).getAttribute("value") ?? "",
  );
  await expect(page.getByRole("heading", { name: "Vue d’ensemble." })).toBeVisible();
  await page.getByRole("button", { name: "Clients", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Clients." })).toBeVisible();
  await expect(page.getByText("Aucun résultat pour le moment.")).toBeVisible();

  // Original company remains selectable by the same administrator.
  await expect(page.getByRole("combobox", { name: "Entreprise active" }).locator(`option[value="${originalCompany}"]`)).toHaveCount(1);
});
