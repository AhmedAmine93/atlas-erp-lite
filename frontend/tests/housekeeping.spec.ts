import { expect, test } from "@playwright/test";

test("P132: workflow housekeeping hôtel, chambre et inspection",async({page})=>{
  if(!process.env.DEMO_PASSWORD)throw Error("Set DEMO_PASSWORD before browser tests");
  const suffix=String(Date.now());
  await page.goto("/");
  await page.getByLabel("Adresse e-mail",{exact:true}).fill("v5-browser-e2e@atlas.example");
  await page.getByLabel("Mot de passe",{exact:true}).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button",{name:"Se connecter",exact:true}).click();
  await expect(page.getByRole("combobox",{name:"Entreprise active"})).toBeVisible();

  await page.getByRole("button",{name:"Établissements hôteliers"}).click();
  await page.getByRole("button",{name:"Nouvelle fiche"}).click();
  await page.getByLabel(/^Code/).fill("HK-"+suffix);
  await page.getByLabel(/^Nom\s*\*/).fill("Hôtel de recette "+suffix);
  await page.getByLabel(/^Fuseau IANA/).fill("Europe/Paris");
  await page.getByRole("button",{name:"Enregistrer"}).click();
  await expect(page.getByText("HK-"+suffix)).toBeVisible();

  await page.getByRole("button",{name:"Chambres",exact:true}).click();
  await page.getByRole("button",{name:"Nouvelle fiche"}).click();
  await page.getByLabel(/^Hôtel\s*\*/).selectOption({label:"HK-"+suffix+" · Hôtel de recette "+suffix});
  await page.getByLabel(/^Numéro\s*\*/).fill("101");
  await page.getByLabel(/^Catégorie\s*\*/).fill("Standard");
  await page.getByRole("button",{name:"Enregistrer"}).click();

  await page.getByRole("button",{name:"Housekeeping",exact:true}).click();
  await expect(page.locator(".atlas-housekeeping")).toBeVisible();
  await page.getByRole("combobox",{name:"Établissement housekeeping"})
    .selectOption({label:"Hôtel de recette "+suffix+" · HK-"+suffix});
  const room=page.locator(".atlas-housekeeping-room").filter({hasText:"Chambre 101"});
  await expect(room).toBeVisible();
  await expect(room.getByText("Sans mission")).toBeVisible();
  await room.getByRole("button",{name:"Créer une mission"}).click();
  await expect(room.getByText("Sale",{exact:true})).toBeVisible();
  await room.getByRole("button",{name:"Commencer"}).click();
  await expect(room.getByText("En cours",{exact:true})).toBeVisible();
  await room.getByRole("button",{name:"Terminer le nettoyage"}).click();
  await expect(room.getByText("Propre",{exact:true})).toBeVisible();
  await room.getByRole("button",{name:"Valider l'inspection"}).click();
  await expect(room.locator('[data-state="inspected"]')).toHaveText("Inspectée");
  await expect(room.getByText("Propreté contrôlée")).toBeVisible();
  await expect(room.getByRole("button")).toBeDisabled();
});
