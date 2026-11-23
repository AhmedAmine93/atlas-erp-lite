import { test, expect } from "@playwright/test";

if (!process.env.DEMO_PASSWORD) throw new Error("Set DEMO_PASSWORD before running browser tests.");

test("M02 CRM : prospects, contacts, opportunités, activités et segments accessibles",async({page})=>{
  await page.goto("/");
  await page.getByLabel("Adresse e-mail",{exact:true}).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe",{exact:true}).fill(process.env.DEMO_PASSWORD!);
  await page.getByRole("button",{name:"Se connecter",exact:true}).click();
  for(const [button,title] of [
    ["Prospects","Prospects"],
    ["Contacts","Contacts"],
    ["Opportunités","Opportunités"],
    ["Activités et relances","Activités et relances"],
    ["Segmentation clients","Segmentation clients"],
  ] as const){
    await page.getByRole("button",{name:button,exact:true}).click();
    await expect(page.getByRole("heading",{name:title,exact:true})).toBeVisible();
  }
  await page.getByRole("button",{name:"Prospects",exact:true}).click();
  await page.getByRole("button",{name:"+ Nouvelle fiche",exact:true}).click();
  const name="Entreprise CRM "+Date.now();
  await page.getByRole("textbox",{name:/^Nom/}).fill(name);
  await page.getByLabel("Email",{exact:true}).fill("crm-browser-"+Date.now()+"@example.test");
  await page.getByRole("button",{name:"Enregistrer",exact:true}).click();
  await expect(page.getByRole("status").filter({hasText:"Fiche enregistrée"})).toBeVisible();
  await expect(page.getByRole("row").filter({hasText:name})).toBeVisible();
});
