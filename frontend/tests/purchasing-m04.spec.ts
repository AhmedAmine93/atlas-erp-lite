import {expect,test} from "@playwright/test";

test("M04: all six purchasing screens are navigable with actual API-backed tables", async ({page})=>{
  if(!process.env.DEMO_PASSWORD)throw new Error("DEMO_PASSWORD requis pour la recette navigateur.");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail",{exact:true}).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe",{exact:true}).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button",{name:"Se connecter",exact:true}).click();
  await expect(page.getByRole("combobox",{name:"Entreprise active"})).toBeVisible();

  for(const [label,heading] of [
    ["Fournisseurs","Fournisseurs"],
    ["Demandes d’achat","Demandes d’achat"],
    ["Consultations fournisseurs","Consultations fournisseurs"],
    ["Commandes fournisseurs","Commandes fournisseurs"],
    ["Réceptions fournisseurs","Réceptions fournisseurs"],
    ["Factures fournisseurs","Factures fournisseurs"],
  ]) {
    await page.getByRole("button",{name:label,exact:true}).click();
    await expect(page.locator(".atlas-domain").getByRole("heading",{name:heading,exact:true})).toBeVisible();
    await expect(page.locator(".atlas-domain-list")).toBeVisible();
    await expect(page.locator(".atlas-domain .error")).toHaveCount(0);
    await expect(page).toHaveURL(/\/workspace\//);
  }
});

test("M04: create supplier, block it, keep supplier visible for historical tracking",async ({page})=>{
  if(!process.env.DEMO_PASSWORD)throw new Error("DEMO_PASSWORD requis.");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail",{exact:true}).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe",{exact:true}).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button",{name:"Se connecter",exact:true}).click();
  await page.getByRole("button",{name:"Fournisseurs",exact:true}).click();
  await page.locator(".atlas-domain").getByRole("button",{name:/Nouvelle fiche/}).click();
  const unique=String(Date.now());
  await page.locator(".atlas-domain-form").getByRole("textbox",{name:/^Nom/}).fill("Fournisseur test M04 "+unique);
  await page.locator(".atlas-domain-form").getByRole("textbox",{name:/^Email/}).fill("m04-"+unique+"@example.test");
  await page.locator(".atlas-domain-form").getByRole("button",{name:"Enregistrer",exact:true}).click();
  const row=page.locator(".atlas-domain-list tbody tr").filter({hasText:"Fournisseur test M04 "+unique});
  await expect(row).toBeVisible();
  page.once("dialog",dialog=>dialog.accept("Test de non-conformité"));
  await row.getByRole("button",{name:"Bloquer",exact:true}).click();
  await expect(row).toContainText("blocked");
});
