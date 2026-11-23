import { expect, test } from "@playwright/test";

test("M09: navigation des six sous-menus RH sur le tenant actif", async ({page}) => {
  const password=process.env.DEMO_PASSWORD;
  if(!password) throw new Error("DEMO_PASSWORD obligatoire");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail",{exact:true}).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe",{exact:true}).fill(password);
  await page.getByRole("button",{name:"Se connecter",exact:true}).click();
  const menus=[
    ["Collaborateurs","Collaborateurs"],
    ["Équipes","Équipes et hiérarchies"],
    ["Planning","Planning RH"],
    ["Congés et absences","Congés et absences"],
    ["Temps passé","Temps passé"],
    ["Compétences et formations","Compétences et formations"],
  ] as const;
  for(const [navigation,heading] of menus){
    const button=page.getByRole("button",{name:navigation,exact:true});
    await expect(button).toBeEnabled();
    await button.click();
    await expect(page.locator(".atlas-domain").getByRole("heading",{name:heading,exact:true})).toBeVisible();
    await expect(page.locator(".atlas-domain-list")).toBeVisible();
    await expect(page.locator(".atlas-domain .error")).toHaveCount(0);
    await expect(page).toHaveURL(/\/workspace\/hr-/);
  }
});
