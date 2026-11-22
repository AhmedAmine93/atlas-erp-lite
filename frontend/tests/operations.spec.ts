import { expect, test } from "@playwright/test";

test("M18: six tenant-aware DevOps screens, synthetic service and read-only evidence", async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) {
    throw new Error("Set DEMO_PASSWORD before running M18 browser tests.");
  }
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();

  const views = [
    ["Supervision générale", "ops-overview"],
    ["Journaux techniques", "ops-logs"],
    ["État des services", "ops-health"],
    ["Jobs et batchs", "ops-jobs"],
    ["Sauvegardes", "ops-backups"],
    ["Déploiements et CI/CD", "ops-deployments"],
  ] as const;

  for (const [label, slug] of views) {
    await page.getByRole("button", { name: label, exact: true }).click();
    await expect(page).toHaveURL(new RegExp("/workspace/"+slug+"$"));
    await expect(page.getByText("Source :", { exact: false })).toBeVisible();
    await expect(page.getByRole("button", { name: "Ajouter une preuve" })).toBeVisible();
  }

  await page.getByRole("button", { name: "État des services", exact: true }).click();
  await page.getByRole("button", { name: "Ajouter une preuve" }).click();
  const unique = "m18-synthetic-" + Date.now();
  await page.getByLabel("Code technique").fill(unique);
  await page.getByLabel("Nom du service").fill("Service de test M18");
  await page.getByLabel("Responsable").fill("SRE fictif");
  await page.getByLabel("Criticité").selectOption("normal");
  await page.getByRole("button", { name: "Enregistrer", exact: true }).click();
  await expect(page.getByRole("status").getByText("Preuve enregistrée", { exact: false })).toBeVisible();
  await expect(page.getByText("Service de test M18")).toBeVisible();
  await page.getByRole("row", { name: /Service de test M18/ }).getByRole("button", { name: "Consulter" }).click();
  await expect(page.getByText(unique)).toBeVisible();
  await expect(page.getByText("Historique immuable")).toBeVisible();
});
