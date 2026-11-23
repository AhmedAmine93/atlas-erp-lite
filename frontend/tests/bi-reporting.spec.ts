import { expect, test } from "@playwright/test";

test("M13: navigation des six écrans BI et garde-fous de restitution", async ({page}) => {
  if (!process.env.DEMO_PASSWORD) throw new Error("DEMO_PASSWORD requis");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail",{exact:true}).fill("v5-browser-e2e-bi@atlas.example");
  await page.getByLabel("Mot de passe",{exact:true}).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button",{name:"Se connecter",exact:true}).click();
  await expect(page.getByRole("combobox",{name:"Entreprise active"})).toBeVisible();

  for (const [name,path] of [
    ["Rapports commerciaux","analytics-sales"],
    ["Analyse financière","analytics-finance"],
    ["Analyse des stocks","analytics-stock"],
    ["Performance fournisseurs","analytics-suppliers"],
    ["Rapports personnalisés","analytics-custom"],
    ["Exports de données","analytics-exports"],
  ]) {
    const button=page.getByRole("button",{name,exact:true});
    await expect(button).toBeEnabled();
    await button.click();
    await expect(page).toHaveURL(new RegExp("/workspace/"+path+"$"));
    await expect(page.locator(".atlas-domain h2")).toBeVisible();
    await expect(page.locator(".atlas-domain .error")).toHaveCount(0);
  }

  await page.getByRole("button",{name:"Analyse financière",exact:true}).click();
  await expect(page.getByText("Encaissements réels",{exact:true})).toBeVisible();
  await expect(page.getByText("Non disponible",{exact:true}).first()).toBeVisible();
  await page.getByRole("button",{name:"Rapports personnalisés",exact:true}).click();
  await expect(page.getByRole("heading",{name:"Nouvelle définition de rapport"})).toBeVisible();
  await page.getByLabel("Titre").fill("Recette BI "+Date.now());
  await page.getByRole("button",{name:"Enregistrer la définition"}).click();
  await expect(page.getByText(/Rapport créé : version 1/)).toBeVisible();
  await page.getByRole("button",{name:"Exports de données",exact:true}).click();
  await expect(page.getByRole("button",{name:"Générer le CSV"})).toBeEnabled();
  await page.getByRole("button",{name:"Générer le CSV"}).click();
  await expect(page.getByText(/Export n°.* créé avec/)).toBeVisible();
});
