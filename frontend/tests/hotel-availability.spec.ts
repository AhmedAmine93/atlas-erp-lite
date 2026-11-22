import { expect, test } from "@playwright/test";

test("P131: disponibilite locale et blocage maintenance sans confusion avec PMS",async({page})=>{
  if(!process.env.DEMO_PASSWORD)throw Error("Set DEMO_PASSWORD before browser tests");
  const suffix=String(Date.now());
  await page.goto("/");
  await page.getByLabel("Adresse e-mail",{exact:true}).fill("v5-browser-e2e@atlas.example");
  await page.getByLabel("Mot de passe",{exact:true}).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button",{name:"Se connecter",exact:true}).click();
  await expect(page.getByRole("combobox",{name:"Entreprise active"})).toBeVisible();

  await page.getByRole("button",{name:"Établissements hôteliers"}).click();
  await page.getByRole("button",{name:"Nouvelle fiche"}).click();
  await page.getByLabel(/^Code/).fill("AV-"+suffix);
  await page.getByLabel(/^Nom\s*\*/).fill("Hôtel disponibilité "+suffix);
  await page.getByLabel(/^Fuseau IANA/).fill("Europe/Paris");
  await page.getByRole("button",{name:"Enregistrer"}).click();
  await expect(page.getByText("AV-"+suffix)).toBeVisible();

  await page.getByRole("button",{name:"Chambres",exact:true}).click();
  await page.getByRole("button",{name:"Nouvelle fiche"}).click();
  await page.getByLabel(/^Hôtel\s*\*/).selectOption({label:"AV-"+suffix+" · Hôtel disponibilité "+suffix});
  await page.getByLabel(/^Numéro\s*\*/).fill("707");
  await page.getByLabel(/^Catégorie\s*\*/).fill("Double");
  await page.getByRole("button",{name:"Enregistrer"}).click();

  await page.getByRole("button",{name:"Disponibilité locale",exact:true}).click();
  await expect(page.getByRole("heading",{name:/Disponibilité des chambres/})).toBeVisible();
  await expect(page.getByText(/ni disponibilité PMS\/CRS externe/)).toBeVisible();
  await page.getByLabel("Hôtel disponibilité").selectOption({label:"Hôtel disponibilité "+suffix+" · AV-"+suffix});
  await page.getByLabel("Arrivée disponibilité").fill("2026-12-10");
  await page.getByLabel("Départ disponibilité").fill("2026-12-12");
  await expect(page.getByRole("row",{name:/707/})).toContainText("Libre localement");

  await page.getByLabel("Chambre à bloquer").selectOption({label:"707 · Double"});
  await page.getByLabel("Type de blocage").selectOption("maintenance");
  await page.getByLabel("Début du blocage").fill("2026-12-10");
  await page.getByLabel("Fin du blocage").fill("2026-12-12");
  await page.getByLabel("Motif de blocage").fill("Maintenance de démonstration");
  await page.getByRole("button",{name:"Créer le blocage"}).click();
  await expect(page.getByText("Blocage créé et audité.")).toBeVisible();
  await expect(page.getByText("Indisponible localement")).toBeVisible();
  await expect(page.getByText("Maintenance prévue")).toBeVisible();

  await page.getByRole("button",{name:"Lever le blocage"}).click();
  await expect(page.getByText("Blocage levé.")).toBeVisible();
  await expect(page.getByText("Libre localement")).toBeVisible();
});
