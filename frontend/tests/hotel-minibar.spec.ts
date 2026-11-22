import {expect, test} from "@playwright/test";

test("P133: saisie minibar, prix historique et rapprochement PMS fictif", async ({page}) => {
  if (!process.env.DEMO_PASSWORD) throw Error("Set DEMO_PASSWORD before browser tests");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", {exact:true}).fill("v5-browser-e2e@atlas.example");
  await page.getByLabel("Mot de passe", {exact:true}).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", {name:"Se connecter",exact:true}).click();
  await expect(page.getByRole("combobox", {name:"Entreprise active"})).toBeVisible();

  const token=(await (await page.request.get("/api/csrf")).json()).token;
  async function post(path:string,data:object) {
    const response=await page.request.post("/api/"+path, {
      data,headers:{"X-CSRF-TOKEN":token,Accept:"application/json"},
    });
    expect(response.ok(),await response.text()).toBeTruthy();
    return response.json();
  }
  const code="MBE2E-"+Date.now();
  const hotel=await post("hotels",{code,name:"Hôtel "+code,timezone:"UTC"});
  await post("hotel-rooms",{hotel_id:hotel.id,room_number:"101",category:"Double"});
  await post("hotels/"+hotel.id+"/minibar/products",{
    sku:"EAU",name:"Eau minérale",effective_on:"2026-01-01",unit_cents:250,
  });

  await page.getByRole("button", {name:"Mini-bar",exact:true}).click();
  await expect(page.getByRole("heading", {name:"Mini-bar",exact:true})).toBeVisible();
  await page.getByLabel("Établissement", {exact:true}).selectOption({label:"Hôtel "+code});
  await page.getByLabel("Journée", {exact:true}).fill("2026-03-03");

  const quantity=page.getByRole("spinbutton", {name:"101 EAU"});
  await expect(quantity).toBeVisible();
  await quantity.fill("2");
  await page.getByRole("button", {name:"Enregistrer les consommations"}).click();
  await expect(page.getByText("1 ligne(s) enregistrée(s).")).toBeVisible();
  await expect(page.getByText("Total hôtel")).toContainText("5,00");

  await page.getByText("Importer un CSV PMS fictif").click();
  await page.getByLabel("Fichier PMS").setInputFiles({
    name:"pms-fictif.csv",mimeType:"text/csv",
    buffer:Buffer.from("business_date;room_number;total_cents\n2026-03-03;101;200\n"),
  });
  await page.getByRole("checkbox", {name:"Le fichier contient des données fictives."}).check();
  await page.getByRole("button", {name:"Importer la journée"}).click();
  await expect(page.getByText(/Import \d+ enregistré/)).toBeVisible();
  const recon=page.getByRole("region", {name:"Rapprochement minibar PMS"});
  await expect(recon).toContainText("Écart");
  await expect(recon).toContainText("3,00");
  await expect(recon).toContainText("Revue manuelle obligatoire");
});
