import { expect, test } from "@playwright/test";

test("réception : valider une arrivée puis un départ anticipé", async ({page}) => {
  const email = "v5-browser-e2e@atlas.example";
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", {exact:true}).fill(email);
  await page.getByLabel("Mot de passe", {exact:true}).fill(process.env.DEMO_PASSWORD!);
  await page.getByRole("button", {name:"Se connecter",exact:true}).click();
  await expect(page.getByRole("combobox",{name:"Entreprise active"})).toBeVisible();

  const token = (await (await page.request.get("/api/csrf")).json()).token;
  async function post(path:string, data:object) {
    const response = await page.request.post("/api/"+path, {data, headers:{"X-CSRF-TOKEN":token, Accept:"application/json"}});
    expect(response.ok(), await response.text()).toBeTruthy();
    return response.json();
  }
  const ref = "REC-"+Date.now();
  const day = (offset:number) => new Date(Date.now()+offset*86400000).toISOString().slice(0,10);
  const hotel = await post("hotels",{code:ref,name:ref,timezone:"UTC"});
  const room = await post("hotel-rooms",{hotel_id:hotel.id,room_number:"101",category:"Standard"});
  await post("hotel-bookings",{room_id:room.id,reference:ref,guest_display_name:"Voyageur fictif",arrival_date:day(0),departure_date:day(2)});
  const task = await post("hotels/housekeeping/tasks",{room_id:room.id,business_date:day(0)});
  for(const status of ["in_progress","clean","inspected"]) await post("hotels/housekeeping/tasks/"+task.id+"/transition",{status});
  await page.getByRole("button",{name:"Arrivées et départs",exact:true}).click();
  const row=page.getByRole("row").filter({hasText:ref});
  await row.getByRole("button",{name:"Confirmer"}).click();
  await row.getByRole("button",{name:"Effectuer le check-in"}).click();
  await row.getByText("Effectuer le check-out").click();
  await row.getByRole("button",{name:"Valider le départ"}).click();
  await expect(page.getByRole("alert")).toBeVisible();
  await row.getByLabel("Motif du départ anticipé").fill("Retour anticipé fictif");
  await row.getByRole("button",{name:"Valider le départ"}).click();
  await expect(row).toContainText("checked_out");
});
