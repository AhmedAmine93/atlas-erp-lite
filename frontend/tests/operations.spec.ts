import {expect,test} from "@playwright/test";
test("M18: six operational sections with explicit evidence disclaimer",async ({page})=>{
 const password=process.env.DEMO_PASSWORD;
 if(!password)throw new Error("DEMO_PASSWORD is required for M18.");
 await page.goto("/");
 await page.getByLabel("Adresse e-mail",{exact:true}).fill("v5-browser-e2e-pilotage@atlas.example");
 await page.getByLabel("Mot de passe",{exact:true}).fill(password);
 await page.getByRole("button",{name:"Se connecter",exact:true}).click();
 for(const name of ["Supervision générale","Journaux techniques","État des services",
  "Jobs et batchs","Sauvegardes","Déploiements et CI/CD"]){
  await page.getByRole("button",{name,exact:true}).click();
  await expect(page.getByRole("heading",{name,exact:true}).last()).toBeVisible();
  await expect(page.getByText("Les preuves sont déclaratives",{exact:false})).toBeVisible();
 }
 await expect(page.getByText("Aucune déclaration.")).toBeVisible();
});