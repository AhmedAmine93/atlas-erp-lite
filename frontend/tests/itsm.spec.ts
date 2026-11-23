import { expect, test } from "@playwright/test";

test("M11: les six nouveaux sous-menus ITSM sont navigables après authentification", async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) {
    throw new Error("Set DEMO_PASSWORD before running browser tests.");
  }
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();

  const sections = [
    ["Centre de tickets", "Créer : Centre de tickets", "support-tickets"],
    ["Problèmes", "Créer : Problèmes", "support-problems"],
    ["Demandes de service", "Catalogue de services", "support-services"],
    ["Changements", "Créer : Changements", "support-changes"],
    ["SLA et escalades", "Horloges SLA des incidents", "support-sla"],
    ["Base de connaissances", "Rechercher une procédure", "support-knowledge"],
  ] as const;

  for (const [button, section, route] of sections) {
    await page.getByRole("button", { name: button, exact: true }).click();
    await expect(page).toHaveURL(new RegExp("/workspace/" + route + "$"));
    await expect(page.getByRole("region", { name: section })).toBeVisible();
    await expect(page.getByRole("alert")).toHaveCount(0);
  }
});

test("M11: création fictive et navigation du ticket", async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) throw new Error("Set DEMO_PASSWORD before running browser tests.");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();
  await page.getByRole("button", { name: "Centre de tickets", exact: true }).click();
  const form = page.getByRole("region", { name: "Créer : Centre de tickets" });
  const title = "M11-DEMO-" + Date.now();
  await form.getByLabel("Titre").fill(title);
  await form.getByLabel("Description").fill("Demande de démonstration pour la recette de l'ERP ATLAS.");
  await form.getByLabel("Priorité").selectOption("P2");
  await form.getByRole("button", { name: "Créer et journaliser" }).click();
  await expect(page.getByRole("status").getByText("Opération enregistrée et journalisée.")).toBeVisible();
  const list = page.getByRole("region", { name: "Liste : Centre de tickets" });
  await expect(list.getByText(title)).toBeVisible();
});
