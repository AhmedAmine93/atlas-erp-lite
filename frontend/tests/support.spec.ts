import { expect, test } from "@playwright/test";

test("F07-F09: support incident, N3 escalation, comments and SLA", async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) {
    throw new Error("Set DEMO_PASSWORD before running browser tests.");
  }

  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();

  await page.getByRole("button", { name: "Incidents", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Centre des incidents" })).toBeVisible();
  await expect(page.getByLabel("Synthèse des incidents")).toBeVisible();

  await page.getByRole("button", { name: "Déclarer un incident" }).click();
  const creation = page.getByRole("dialog", { name: "Déclarer un incident" });
  const unique = "F07-F09-" + Date.now();
  await creation.getByLabel("Titre").fill(unique);
  await creation.getByLabel("Description (sans secret ni données réelles)").fill("Requête SQL lente dans une base fictive.");
  await creation.getByLabel("Priorité").selectOption("P2");
  await creation.getByRole("button", { name: "Créer l'incident" }).click();
  await expect(page.getByText(unique)).toBeVisible();

  await page.getByRole("row", { name: new RegExp(unique) }).getByRole("button", { name: "Consulter" }).click();
  const detail = page.getByRole("dialog", { name: "Détail de l'incident" });
  await expect(detail.getByText("Niveau d'escalade : N2")).toBeVisible();
  await expect(detail.getByText("Délai résolution :", { exact: false })).toBeVisible();

  await detail.getByLabel("Commentaire support").fill("Diagnostic N2 sur des données de démonstration.");
  await detail.getByRole("button", { name: "Ajouter un commentaire" }).click();
  await expect(detail.getByText("Diagnostic N2 sur des données de démonstration.")).toBeVisible();
  await expect(page.getByRole("status").getByText("Commentaire ajouté.")).toBeVisible();

  await detail.getByLabel("Motif d'escalade N3").fill("Analyse des plans d'exécution Oracle nécessaire.");
  await expect(detail.getByLabel("Motif d'escalade N3")).toHaveValue("Analyse des plans d'exécution Oracle nécessaire.");
  await expect(detail.getByRole("button", { name: "Escalader vers N3" })).toBeEnabled();
  await detail.getByRole("button", { name: "Escalader vers N3" }).click();
  await expect(detail.getByText("Niveau d'escalade : N3")).toBeVisible();

  await detail.getByRole("button", { name: "Passer à : En investigation" }).click();
  await expect(detail.getByText("Statut : En investigation", { exact: false })).toBeVisible();
  await detail.getByLabel("Compte rendu de résolution").fill("Index et requête optimisés sur des données fictives.");
  await detail.getByRole("button", { name: "Passer à : Résolu" }).click();
  await expect(detail.getByText("Index et requête optimisés sur des données fictives.")).toBeVisible();
  await detail.getByRole("button", { name: "Passer à : Clôturé" }).click();
  await expect(detail.getByText("Statut : Clôturé", { exact: false })).toBeVisible();
});
