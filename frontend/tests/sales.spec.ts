import { test, expect } from "@playwright/test";
if (!process.env.DEMO_PASSWORD) throw new Error("Set DEMO_PASSWORD before running browser tests.");
test("client → devis → commande → facture PDF → payée", async ({ page }) => {
  const name = `Parcours ${Date.now()}`;
  await page.goto("/");
  await page
    .getByLabel("Adresse e-mail", { exact: true })
    .fill("admin@atlas.example");
  await page
    .getByLabel("Mot de passe", { exact: true })
    .fill(process.env.DEMO_PASSWORD!);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();
  await page.getByRole("button", { name: "Clients", exact: true }).click();
  await page
    .getByRole("button", { name: "Nouveau client", exact: true })
    .click();
  await page.getByLabel("Nom / entreprise", { exact: true }).fill(name);
  await page
    .getByLabel("Adresse e-mail", { exact: true })
    .fill(`${name.replace(" ", "-")}@client.example`);
  await page.getByRole("button", { name: "Enregistrer", exact: true }).click();
  await expect(page.getByRole("dialog")).toBeHidden();
  await page.getByRole("button", { name: "Devis", exact: true }).click();
  await page
    .getByRole("button", { name: "Nouveau devis", exact: true })
    .click();
  await page
    .getByRole("combobox", { name: "Client", exact: true })
    .selectOption({ label: name });
  // Seeded product; replenishment is explicit so the test is repeatable.
  const csrf = (await (await page.request.get("/api/csrf")).json()).token;
  const productPage = await (
    await page.request.get("/api/products?search=BUR-001")
  ).json();
  const product = productPage.data[0];
  expect(product).toBeTruthy();
  const replenish = await page.request.post(
    `/api/products/${product.id}/stock`,
    {
      headers: { "X-CSRF-TOKEN": csrf },
      data: { quantity: 2, reason: "Préparation test navigateur" },
    },
  );
  expect(replenish.ok()).toBeTruthy();
  await page
    .getByRole("combobox", { name: "Produit 1", exact: true })
    .selectOption(String(product.id));
  await page
    .getByRole("spinbutton", { name: "Quantité 1", exact: true })
    .fill("2");
  await page.getByRole("button", { name: "Enregistrer", exact: true }).click();
  await expect(page.getByRole("dialog")).toBeHidden();
  await page
    .getByRole("row")
    .filter({ hasText: name })
    .getByRole("button", { name: "Ouvrir" })
    .click();
  await page.getByRole("button", { name: "Convertir en commande" }).click();
  await expect(page.getByRole("dialog")).toBeHidden();
  await page.getByRole("button", { name: "Commandes", exact: true }).click();
  const orderRow = page.getByRole("row").filter({ hasText: name });
  await orderRow.getByRole("button", { name: "Ouvrir" }).click();
  await page.getByRole("button", { name: "Valider la commande" }).click();
  await expect(page.getByRole("dialog")).toBeHidden();
  await expect(orderRow).toContainText("Validée");
  await orderRow.getByRole("button", { name: "Ouvrir" }).click();
  await page.getByRole("button", { name: "Créer la facture" }).click();
  await expect(page.getByRole("dialog")).toBeHidden();
  await page.getByRole("button", { name: "Factures", exact: true }).click();
  const invoiceRow = page.getByRole("row").filter({ hasText: name });
  await invoiceRow.getByRole("button", { name: "Ouvrir" }).click();
  await page.getByRole("button", { name: "Émettre la facture" }).click();
  await expect(page.getByRole("dialog")).toBeHidden();
  await expect(invoiceRow).toContainText("Émise");
  await invoiceRow.getByRole("button", { name: "Ouvrir" }).click();
  const downloadPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "Télécharger le PDF" }).click();
  expect((await downloadPromise).suggestedFilename()).toMatch(/^FAC-.*\.pdf$/);
  await page.getByRole("button", { name: "Marquer comme payée" }).click();
  await expect(page.getByRole("dialog")).toBeHidden();
  await expect(invoiceRow).toContainText("Payée");
  await page.getByRole("button", { name: "Se déconnecter" }).click();
  await expect(
    page.getByRole("button", { name: "Se connecter", exact: true }),
  ).toBeVisible();
});
test("lecture seule et affichage mobile", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/");
  await page
    .getByLabel("Adresse e-mail", { exact: true })
    .fill("reader@atlas.example");
  await page
    .getByLabel("Mot de passe", { exact: true })
    .fill(process.env.DEMO_PASSWORD!);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Le rythme de vos ventes" }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () =>
        document.documentElement.scrollWidth <=
        document.documentElement.clientWidth,
    ),
  ).toBeTruthy();
  await page.getByRole("button", { name: "Ouvrir le menu" }).click();
  await page.getByRole("button", { name: "Clients", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Clients." })).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Nouveau client" }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Utilisateurs", exact: true }),
  ).toHaveCount(0);
});
